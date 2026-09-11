{{--
    Deployment for the mizan app server. Run from a checkout:

        php vendor/bin/envoy run deploy

    The work itself is deploy/deploy.sh, which lives in the repo and is the same
    script whether it is run by hand on the server or by this file — there is no
    second copy of the deploy steps to keep in sync. What Envoy adds is the two
    things that script deliberately leaves to the caller: getting the new code
    onto the server first, and reloading PHP-FPM afterwards.
--}}

{{-- The host is not hardcoded because this repo is public: naming the box and
    the login it takes is free reconnaissance. `vps` is an ~/.ssh/config alias
    (HostName + User + key); override with MIZAN_DEPLOY_HOST to deploy elsewhere. --}}
@servers(['mizan' => getenv('MIZAN_DEPLOY_HOST') ?: 'vps'])

@setup
    // Branch is overridable: `envoy run deploy --branch=hotfix`.
    $branch = $branch ?? 'master';
    $path = '/var/www/mizan';
@endsetup

@task('deploy', ['on' => 'mizan'])
    set -e
    cd {{ $path }}

    # The checkout belongs to the PHP-FPM user while this runs as root, and git
    # refuses a repository it does not own until the exception is explicit. Guarded
    # rather than plain --add, which appends a duplicate every deploy.
    git config --global --get-all safe.directory | grep -qx '{{ $path }}' \
        || git config --global --add safe.directory '{{ $path }}'

    # `reset --hard` discards local edits to *tracked* files on the server, which is
    # the point: the server is a checkout, not somewhere to edit. .env, vendor/ and
    # public/build/ are untracked or ignored and survive.
    git fetch --prune origin
    git checkout {{ $branch }}
    git reset --hard origin/{{ $branch }}

    deploy/deploy.sh

    # deploy.sh runs as root here, so anything it wrote (caches, compiled views) is
    # root-owned until this puts it back to the PHP-FPM user.
    chown -R nginx:nginx {{ $path }}

    # Not optional, and now doubly so: deploy/php/opcache.ini sets
    # validate_timestamps=0, and Octane additionally holds the booted framework in
    # memory between requests. Without this the workers keep serving the release
    # before this one no matter what git says. `octane:reload` is graceful — workers
    # finish their current request before being replaced, so no request is dropped.
    sudo -u nginx php artisan octane:reload

    # PHP-FPM is no longer in the request path, but is left running so that reverting
    # the vhost is the only step needed to fall back to it.
    systemctl reload php-fpm

    # Workers hold the old code until they are replaced. deploy.sh calls
    # horizon:terminate; systemd's Restart=always brings it back on the new release.
    systemctl restart mizan-reverb
@endtask
