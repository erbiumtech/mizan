const { join } = require('path');

/**
 * Where Puppeteer keeps the browser it downloads.
 *
 * Its default is `$HOME/.cache/puppeteer`, and on the app server that is two
 * different directories: `npm ci` runs as whoever deploys (root) and writes to
 * `/root/.cache`, while Octane and the queue workers run as `nginx` and look in
 * `/var/lib/nginx/.cache`. The browser is then present and invisible, and every
 * PDF fails with "Could not find chrome-headless-shell" — a 500 on the download
 * button, not a fallback, because `PDF_DRIVER=browsershot` names the engine.
 *
 * Pinning it to the project puts install and runtime in the same place for every
 * user. Gitignored, and outside node_modules so `npm ci` does not re-download it.
 */
module.exports = {
    cacheDirectory: join(__dirname, '.cache', 'puppeteer'),
};
