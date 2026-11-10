FROM php:8.3.36-fpm

# nginx lives in this image too. It is a second process rather than a second
# container because the two are not independently useful: nginx here serves
# exactly one document root, from the same files php-fpm executes, and neither
# is ever scaled or restarted without the other. Splitting them bought a
# FastCGI hop over a Docker network and a bind mount of the code into two
# places; joining them means one build, one log stream and one thing to stop.
#
# p7zip-full is v1 baggage. Since v2.0.0 every archive is libsodium (PHP's
# built-in sodium extension, no package needed); 7z is kept only so a v1 stash
# can be read once and upgraded (App/src/MigrateV2.php, Docs/v2_migration.md).
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        p7zip-full \
        procps \
        nginx-light \
    && rm -rf /var/lib/apt/lists/*

# The Debian package ships a site on port 80 serving its own welcome page.
# Nothing includes it once this is gone; the app's server block arrives below
# in conf.d, which nginx.conf also includes.
RUN rm -f /etc/nginx/sites-enabled/default

# GHCR links a package to a repository by this label, and a package that is not
# linked to one is a package the repository's GITHUB_TOKEN cannot write to —
# which is how a workflow with `packages: write` still gets
# "denied: permission_denied: read_package". The first hand-pushed image had no
# label, so the package was created unlinked and CI could not publish over it.
LABEL org.opencontainers.image.source="https://github.com/HermanRas/MyStash"
LABEL org.opencontainers.image.description="MyStash — a private, self-hosted, encrypted video wall. nginx and php-fpm in one container."

WORKDIR /app

COPY . /app
COPY php.ini /usr/local/etc/php/conf.d/mystash.ini
# Staged outside conf.d, not in it: the entrypoint moves it into place
# unless MYSTASH_DEV=1. Production hardening therefore applies to anyone
# who runs this image without saying otherwise, which is the way round it
# needs to be — a deployment that forgets the file gets a container that
# prints PHP notices to the browser, and that is how a full /dev/shm
# surfaced as a stack trace on mystash.dragoon.co.za instead of a log line.
COPY php.prod.ini /usr/local/etc/php/mystash-prod.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-mystash.conf
COPY nginx.conf /etc/nginx/conf.d/default.conf
# --chmod rather than relying on the mode in the checkout: this repository is
# worked on from a Windows drive mount, where git has core.filemode=false and
# records every file as 0644. The bit is set in the index too, but the image
# must not depend on that having been remembered.
COPY --chmod=0755 docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# nginx listens; php-fpm is reached over the loopback inside this container and
# is not published anywhere.
EXPOSE 8080

CMD ["/usr/local/bin/docker-entrypoint.sh"]
