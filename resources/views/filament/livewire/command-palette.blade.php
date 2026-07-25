<div
    x-data="{
        query: '',
        results: [],
        active: 0,
        recents: JSON.parse(localStorage.getItem('cp-recents') || '[]'),
        get flatCount() { return this.results.reduce((n, g) => n + g.items.length, 0) },
        idx(gi, ii) { let n = 0; for (let i = 0; i < gi; i++) n += this.results[i].items.length; return n + ii },
        flatAt(k) { let n = 0; for (const g of this.results) { if (k < n + g.items.length) return g.items[k - n]; n += g.items.length } return null },
        async load() {
            const server = await $wire.search(this.query);
            this.results = (this.query === '' && this.recents.length)
                ? [{ group: 'Recent', items: this.recents }, ...server]
                : server;
            this.active = 0;
        },
        remember(it) {
            if (! it.url) return;
            this.recents = [{ label: it.label, subtitle: it.subtitle, url: it.url, icon: it.icon },
                ...this.recents.filter(r => r.url !== it.url)].slice(0, 5);
            localStorage.setItem('cp-recents', JSON.stringify(this.recents));
        },
        openPalette() {
            this.query = '';
            this.$refs.dialog.showModal();
            this.$nextTick(() => { this.$refs.input.focus(); this.load() });
        },
        close() { this.$refs.dialog.close() },
        move(d) { if (! this.flatCount) return; this.active = (this.active + d + this.flatCount) % this.flatCount; this.scrollActive() },
        scrollActive() { this.$nextTick(() => { const el = this.$refs.dialog.querySelector('#cp-opt-' + this.active); if (el) el.scrollIntoView({ block: 'nearest' }) }) },
        run(newTab = false) {
            const it = this.flatAt(this.active);
            if (! it) return;
            if (it.command === 'logout') { this.$refs.logoutForm.submit(); return; }
            if (it.command === 'toggle-theme') { this.toggleTheme(); this.close(); return; }
            if (it.url) { this.remember(it); newTab ? window.open(it.url, '_blank') : (window.location.href = it.url) }
            this.close();
        },
        toggleTheme() {
            const next = (localStorage.theme === 'dark') ? 'light' : 'dark';
            localStorage.theme = next;
            document.documentElement.classList.toggle('dark', next === 'dark');
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: next }));
        },
        iconFor(group) {
            const paths = {
                'Recent': 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
                'Commands': 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
                'Resources': 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 8.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25A2.25 2.25 0 0113.5 8.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
                'Pages': 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
                'Records': 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
            };
            return paths[group] || paths['Pages'];
        },
    }"
    @keydown.window.meta.k.prevent="openPalette()"
    @keydown.window.ctrl.k.prevent="openPalette()"
    @open-command-palette.window="openPalette()"
>
    <form method="POST" action="{{ \Filament\Facades\Filament::getLogoutUrl() }}" x-ref="logoutForm" class="cp-hidden">@csrf</form>

    <dialog
        x-ref="dialog"
        wire:ignore
        class="cp-dialog"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="run($event.metaKey || $event.ctrlKey)"
        @keydown.esc.prevent="close()"
        @click="if ($event.target === $refs.dialog) close()"
    >
        <div class="cp-search">
            <svg class="cp-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
            <input
                x-ref="input"
                x-model="query"
                @input.debounce.150ms="load()"
                type="text"
                placeholder="Search…"
                autocomplete="off"
                role="combobox"
                aria-expanded="true"
                aria-controls="cp-listbox"
                :aria-activedescendant="'cp-opt-' + active"
            >
        </div>

        <div class="cp-list" id="cp-listbox" role="listbox">
            <template x-if="! flatCount">
                <p class="cp-empty">No results.</p>
            </template>

            <template x-for="(group, gi) in results" :key="group.group">
                <div>
                    <p class="cp-group" x-text="group.group"></p>
                    <template x-for="(item, ii) in group.items" :key="group.group + '-' + item.label">
                        <button
                            type="button"
                            role="option"
                            :id="'cp-opt-' + idx(gi, ii)"
                            :aria-selected="idx(gi, ii) === active"
                            class="cp-item"
                            :class="{ 'cp-item-active': idx(gi, ii) === active }"
                            @mouseenter="active = idx(gi, ii)"
                            @click="active = idx(gi, ii); run($event.metaKey || $event.ctrlKey)"
                        >
                            <template x-if="item.icon"><span class="cp-item-icon-wrap" x-html="item.icon"></span></template>
                            <template x-if="! item.icon">
                                <svg class="cp-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="iconFor(group.group)" />
                                </svg>
                            </template>
                            <span class="cp-item-label" x-text="item.label"></span>
                            <span class="cp-item-subtitle" x-text="item.subtitle"></span>
                        </button>
                    </template>
                </div>
            </template>
        </div>

        <div class="cp-footer">
            <span><kbd>esc</kbd> Close</span>
            <span><kbd>↵</kbd> Go to page</span>
        </div>
    </dialog>

    <style>
        .cp-hidden { display: none; }

        .cp-dialog {
            position: fixed;
            inset: 12vh 0 auto 0;
            margin-inline: auto;
            width: min(640px, calc(100vw - 2rem));
            max-width: none;
            padding: 0;
            border: none;
            border-radius: 14px;
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 25px 50px -12px rgba(2, 6, 23, .35), 0 0 0 1px rgba(2, 6, 23, .05);
            overflow: hidden;
        }
        .cp-dialog::backdrop { background: rgba(2, 6, 23, .5); }

        .cp-search { display: flex; align-items: center; gap: .625rem; padding: 0 1rem; border-bottom: 1px solid #e5e7eb; }
        .cp-search-icon { width: 20px; height: 20px; color: #9ca3af; flex: none; }
        .cp-search input { flex: 1; border: 0; outline: none; background: transparent; padding: .875rem 0; font-size: 1rem; color: inherit; }
        .cp-search input::placeholder { color: #9ca3af; }

        .cp-list { max-height: 22rem; overflow-y: auto; padding: .5rem; }
        .cp-empty { padding: 1.5rem; text-align: center; color: #6b7280; font-size: .875rem; }

        .cp-group { position: sticky; top: 0; background: #ffffff; padding: .5rem .5rem .25rem; font-size: .6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #9ca3af; }

        .cp-item { display: flex; align-items: center; gap: .75rem; width: 100%; padding: .5rem .625rem; border: 0; background: transparent; border-radius: 8px; text-align: left; cursor: pointer; color: inherit; }
        .cp-item-active { background: rgba(var(--primary-500, 245 158 11), .12); }
        .cp-item-active .cp-item-label { color: rgb(var(--primary-700, 180 83 9)); }
        .cp-item-active .cp-item-icon, .cp-item-active .cp-item-icon-wrap { color: rgb(var(--primary-600, 217 119 6)); }
        .cp-item-icon, .cp-item-icon-wrap { width: 18px; height: 18px; color: #6b7280; flex: none; display: inline-flex; }
        .cp-item-icon-wrap svg { width: 18px; height: 18px; }
        .cp-item-label { font-size: .875rem; font-weight: 500; }
        .cp-item-subtitle { font-size: .8125rem; color: #9ca3af; }

        .cp-footer { display: flex; justify-content: space-between; align-items: center; padding: .5rem 1rem; border-top: 1px solid #e5e7eb; font-size: .75rem; color: #9ca3af; }
        .cp-footer kbd { display: inline-block; min-width: 1.25rem; padding: .0625rem .25rem; margin-right: .25rem; border-radius: 4px; background: #f3f4f6; color: #6b7280; font-size: .6875rem; text-align: center; }

        .dark .cp-dialog { background: #18181b; color: #f4f4f5; box-shadow: 0 25px 50px -12px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.06); }
        .dark .cp-search { border-color: #27272a; }
        .dark .cp-group { background: #18181b; color: #71717a; }
        .dark .cp-item-active { background: rgba(var(--primary-400, 251 191 36), .14); }
        .dark .cp-item-active .cp-item-label { color: rgb(var(--primary-300, 252 211 77)); }
        .dark .cp-item-active .cp-item-icon, .dark .cp-item-active .cp-item-icon-wrap { color: rgb(var(--primary-400, 251 191 36)); }
        .dark .cp-item-subtitle, .dark .cp-item-icon, .dark .cp-item-icon-wrap { color: #a1a1aa; }
        .dark .cp-footer { border-color: #27272a; }
        .dark .cp-footer kbd { background: #27272a; color: #a1a1aa; }
    </style>
</div>
