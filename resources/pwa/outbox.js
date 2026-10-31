/*
 * The client outbox — docs/desktop-mobile-plan.md §5a rule 1, built in O1.
 *
 * A command captured offline is a row in IndexedDB: a ULID minted here, the workflow, the payload, the
 * device's time, and whose session it belongs to. When the browser says it is online — on load and on
 * the `online` event — the rows are replayed strictly in ULID order (a ULID sorts by the moment it was
 * minted), one batch per workflow, to the session endpoint the panel left in localStorage along with the
 * CSRF token. The server answers per command: applied, replayed, rejected, stale and conflict are final
 * and the row is deleted; failed and skipped are not, and the outbox backs off and tries again. A 401 or
 * 419 means the session is gone and the person has to sign in, a 403 that the module is not licensed:
 * both stop the replay and are shown, never retried blindly ("stop syncing, tell the user").
 *
 * Files ride behind their command (rule 5, O2). A photograph given to enqueue() is kept in a second
 * store, keyed by its own ULID and naming the command it belongs to, and is uploaded on its own, one at
 * a time, only once that command has left the outbox — so a photograph never arrives before the diary
 * line it belongs to, and a slow upload never holds up the lines behind it. `stale` (O2) is a change to
 * a snag made against a copy the server has moved past: kept on the item for a person, not applied.
 *
 * No framework, no build step: this file is inlined into the offline page — which is all the browser
 * has when the network is gone — and into the panel, by the same Blade partial. Idempotent, because
 * the panel re-runs its scripts on every wire:navigate.
 */
(function () {
    if (window.MizanSync) {
        return;
    }

    const DB_NAME = 'mizan-sync';
    const STORE = 'outbox';
    const BLOBS = 'blobs';
    const CONTEXT_KEY = 'mizan.sync.context';
    const STATE_KEY = 'mizan.sync.state';
    const BATCH = 100;
    const BACKOFF_MS = [2000, 5000, 15000, 60000, 300000];

    // --- ULID: Crockford base32, 10 characters of time and 16 of randomness -------------------------
    const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    function ulid(now) {
        let time = now === undefined ? Date.now() : now;
        let out = '';

        for (let i = 9; i >= 0; i--) {
            out = ALPHABET[time % 32] + out;
            time = Math.floor(time / 32);
        }

        const random = new Uint8Array(16);

        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(random);
        } else {
            for (let i = 0; i < 16; i++) {
                random[i] = Math.floor(Math.random() * 256);
            }
        }

        for (let i = 0; i < 16; i++) {
            out += ALPHABET[random[i] % 32];
        }

        return out;
    }

    // --- Storage ------------------------------------------------------------------------------------
    function open() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB is not available'));

                return;
            }

            // Version 2 adds the photographs' store (O2); a browser holding O1's outbox keeps its rows.
            const request = window.indexedDB.open(DB_NAME, 2);

            request.onupgradeneeded = () => {
                const db = request.result;

                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'id' });
                }

                if (!db.objectStoreNames.contains(BLOBS)) {
                    db.createObjectStore(BLOBS, { keyPath: 'id' });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function tx(mode, work, storeName) {
        const name = storeName || STORE;

        return open().then((db) => new Promise((resolve, reject) => {
            const transaction = db.transaction(name, mode);
            const store = transaction.objectStore(name);
            let result;

            transaction.oncomplete = () => {
                db.close();
                resolve(result);
            };
            transaction.onerror = () => {
                db.close();
                reject(transaction.error);
            };
            transaction.onabort = () => {
                db.close();
                reject(transaction.error || new Error('The outbox transaction was aborted'));
            };

            // A failure inside the work is the transaction's failure: abort it so nothing it queued
            // commits, and answer with the work's own error rather than leaving it unhandled while the
            // empty transaction completes and this promise resolves with nothing.
            Promise.resolve().then(() => work(store)).then((value) => {
                result = value;
            }, (error) => {
                try {
                    transaction.abort();
                } catch (e) {
                    // Already finished; the rejection below is still the answer.
                }

                db.close();
                reject(error);
            });
        }));
    }

    function all(storeName) {
        return tx('readonly', (store) => new Promise((resolve, reject) => {
            const request = store.getAll();
            request.onsuccess = () => resolve((request.result || []).sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0)));
            request.onerror = () => reject(request.error);
        }), storeName);
    }

    function readJson(key) {
        try {
            const raw = window.localStorage.getItem(key);

            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writeJson(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Private mode or a full quota: the outbox still works for this page's lifetime.
        }
    }

    // --- The outbox ---------------------------------------------------------------------------------
    let flushing = false;
    let attempt = 0;
    let timer = null;

    function context() {
        return readJson(CONTEXT_KEY);
    }

    /**
     * What the panel knows and the offline page cannot: who is signed in, which company, where each
     * workflow's endpoint is, and the CSRF token the session accepts. Written on every panel page.
     */
    function setContext(next) {
        writeJson(CONTEXT_KEY, next);
        writeJson(STATE_KEY, Object.assign(state(), { blocked: null }));
    }

    function state() {
        return readJson(STATE_KEY) || { blocked: null, lastRejected: null, lastSyncedAt: null };
    }

    function setState(patch) {
        const next = Object.assign(state(), patch);
        writeJson(STATE_KEY, next);

        return next;
    }

    function announce(detail) {
        window.dispatchEvent(new CustomEvent('mizan-sync', { detail }));
    }

    /**
     * Keep a command, and any files that ride behind it: `files` is a list of { file, caption, subject }.
     * The command is written first; each file gets its own ULID, minted after the command's so it sorts
     * after it, and names the command it belongs to.
     */
    function enqueue(workflow, payload, capturedAt, files) {
        const ctx = context();
        const row = {
            id: ulid(),
            workflow,
            payload,
            captured_at: capturedAt || new Date().toISOString(),
            user: ctx ? ctx.user : null,
            company: ctx ? ctx.company : null,
        };

        const blobs = (files || []).filter((item) => item && item.file).map((item) => ({
            id: ulid(),
            command: row.id,
            workflow,
            file: item.file,
            name: item.file.name || 'photo.jpg',
            fields: { caption: item.caption || '', subject: item.subject || '' },
            captured_at: row.captured_at,
            user: row.user,
            company: row.company,
        }));

        return tx('readwrite', (store) => {
            store.put(row);
        })
            .then(() => (blobs.length ? tx('readwrite', (store) => {
                blobs.forEach((blob) => store.put(blob));
            }, BLOBS) : null))
            .then(() => {
                schedule(0);

                return row;
            });
    }

    function pending(workflow) {
        return all().then((rows) => rows.filter((row) => !workflow || row.workflow === workflow));
    }

    /** Photographs still on this device — "N photos pending" until each one lands. */
    function pendingBlobs(workflow) {
        return all(BLOBS).then((rows) => rows.filter((row) => !workflow || row.workflow === workflow));
    }

    function remove(ids, storeName) {
        if (!ids.length) {
            return Promise.resolve();
        }

        return tx('readwrite', (store) => {
            ids.forEach((id) => store.delete(id));
        }, storeName);
    }

    function schedule(delay) {
        if (timer) {
            window.clearTimeout(timer);
        }

        timer = window.setTimeout(() => {
            timer = null;
            flush();
        }, delay);
    }

    function backoff() {
        const delay = BACKOFF_MS[Math.min(attempt, BACKOFF_MS.length - 1)];
        attempt += 1;
        schedule(delay);
    }

    /**
     * Replay everything that belongs to the signed-in person, in order, one batch per workflow.
     * Resolves to a summary; never rejects — a failure is a state to show, not an exception.
     */
    async function flush() {
        if (flushing || !navigator.onLine) {
            return { sent: 0, reason: flushing ? 'busy' : 'offline' };
        }

        const ctx = context();

        if (!ctx || !ctx.endpoints || !ctx.csrf) {
            return { sent: 0, reason: 'no-context' };
        }

        flushing = true;
        const summary = { applied: 0, replayed: 0, rejected: 0, stale: 0, conflict: 0, failed: 0, blocked: null, pending: 0, photos: 0, photosRefused: 0, photosPending: 0, byWorkflow: {} };

        try {
            const rows = (await all()).filter((row) => row.user === ctx.user && row.company === ctx.company);
            const byWorkflow = {};

            rows.forEach((row) => {
                (byWorkflow[row.workflow] = byWorkflow[row.workflow] || []).push(row);
            });

            for (const workflow of Object.keys(byWorkflow)) {
                const endpoint = ctx.endpoints[workflow];

                if (!endpoint) {
                    summary.pending += byWorkflow[workflow].length;

                    continue;
                }

                let stop = false;

                for (let offset = 0; offset < byWorkflow[workflow].length && !stop; offset += BATCH) {
                    const batch = byWorkflow[workflow].slice(offset, offset + BATCH);
                    let response;

                    try {
                        response = await fetch(endpoint, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': ctx.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                commands: batch.map((row) => ({ id: row.id, payload: row.payload, captured_at: row.captured_at })),
                            }),
                        });
                    } catch (e) {
                        // The network went again. Everything in this batch and after stays.
                        summary.failed += 1;
                        stop = true;

                        break;
                    }

                    if (response.status === 401 || response.status === 419) {
                        summary.blocked = 'session';
                        stop = true;

                        break;
                    }

                    if (response.status === 403) {
                        summary.blocked = 'module';
                        stop = true;

                        break;
                    }

                    if (!response.ok) {
                        summary.failed += 1;
                        stop = true;

                        break;
                    }

                    const body = await response.json();
                    const done = [];

                    (body.results || []).forEach((result) => {
                        if (['applied', 'replayed', 'rejected', 'stale', 'conflict'].includes(result.status)) {
                            done.push(result.id);
                            summary[result.status] += 1;

                            const counts = summary.byWorkflow[workflow] = summary.byWorkflow[workflow] || {};
                            counts[result.status] = (counts[result.status] || 0) + 1;

                            if (result.status === 'rejected' || result.status === 'stale') {
                                setState({ lastRejected: { id: result.id, workflow, status: result.status, message: result.result && result.result.message, at: new Date().toISOString() } });
                            }
                        } else {
                            summary.failed += 1;
                            stop = true;
                        }
                    });

                    await remove(done);
                }
            }

            if (!summary.blocked && summary.failed === 0) {
                await uploadBlobs(ctx, summary);
            }

            summary.pending += (await all()).filter((row) => row.user === ctx.user && row.company === ctx.company).length;
            summary.photosPending = (await all(BLOBS)).filter((row) => row.user === ctx.user && row.company === ctx.company).length;

            if (summary.blocked) {
                setState({ blocked: summary.blocked });
            } else if (summary.failed > 0) {
                backoff();
            } else {
                attempt = 0;
                setState({ blocked: null, lastSyncedAt: new Date().toISOString() });
            }

            announce(summary);

            return summary;
        } catch (e) {
            summary.failed += 1;
            backoff();
            announce(summary);

            return summary;
        } finally {
            flushing = false;
        }
    }

    /**
     * Upload the photographs whose command has left the outbox, one at a time, oldest first. A file whose
     * command is still waiting waits with it. The server answers with the inbox's entry: applied and
     * replayed are done, rejected and conflict are final (the line it belonged to was refused, or the file
     * is not this person's), and a 422 from validation — a file over the cap — is final too.
     */
    async function uploadBlobs(ctx, summary) {
        const waiting = new Set((await all()).map((row) => row.id));
        const blobs = (await all(BLOBS)).filter((blob) => blob.user === ctx.user && blob.company === ctx.company && !waiting.has(blob.command));

        for (const blob of blobs) {
            const endpoint = ctx.endpoints[blob.workflow + ':blobs'];

            if (!endpoint) {
                continue;
            }

            const form = new FormData();
            form.append('id', blob.id);
            form.append('command_id', blob.command);
            form.append('captured_at', blob.captured_at);
            form.append('file', blob.file, blob.name);
            Object.keys(blob.fields || {}).forEach((key) => {
                if (blob.fields[key]) {
                    form.append(key, blob.fields[key]);
                }
            });

            let response;

            try {
                response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': ctx.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: form,
                });
            } catch (e) {
                summary.failed += 1;

                return;
            }

            if (response.status === 401 || response.status === 419) {
                summary.blocked = 'session';

                return;
            }

            if (response.status === 403) {
                summary.blocked = 'module';

                return;
            }

            if (response.status === 422) {
                summary.photosRefused += 1;
                await remove([blob.id], BLOBS);

                continue;
            }

            if (!response.ok) {
                summary.failed += 1;

                return;
            }

            const body = await response.json();
            const status = body.result && body.result.status;

            if (status === 'applied' || status === 'replayed') {
                summary.photos += 1;
                await remove([blob.id], BLOBS);
            } else if (status === 'rejected' || status === 'conflict') {
                summary.photosRefused += 1;
                setState({ lastRejected: { id: blob.id, workflow: blob.workflow, status, message: body.result.result && body.result.result.message, at: new Date().toISOString() } });
                await remove([blob.id], BLOBS);
            } else {
                summary.failed += 1;

                return;
            }
        }
    }

    window.addEventListener('online', () => {
        attempt = 0;
        schedule(500);
    });

    window.MizanSync = { ulid, enqueue, pending, pendingBlobs, flush, context, setContext, state };
})();
