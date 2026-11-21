# ffmpeg comes from a static upstream build, not from Debian. Debian's trixie
# package (7.1.x, nine libav* packages plus their X11/tiff/sndfile tree) was
# ~216 of the 432 HIGH findings in the lab's Trivy scan, none with a Debian fix.
# Upstream releases carry those fixes. The trade: Trivy cannot see a static
# binary's version, so this tag is what has to be kept current — Dependabot
# watches it (.github/dependabot.yml). libx264, libx265 and aac are all the app
# asks for (VideoEncoder.php) and all are in this build.
FROM mwader/static-ffmpeg:9.0.2-1 AS ffmpeg

FROM php:8.3.36-fpm

COPY --from=ffmpeg /ffmpeg /ffprobe /usr/local/bin/

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
#
# `upgrade` because the base image is only as fresh as the day it was tagged:
# the lab scan found nginx a Debian security release behind (12 CRITICAL) with
# the fix already published. CI rebuilds this layer weekly without cache
# (.github/workflows/image.yml) so those land without anyone editing this file.
#
# The purge drops the compiler toolchain the php image keeps for
# docker-php-ext-install, which this image never runs. linux-libc-dev alone was
# 70 findings — kernel CVEs, in headers, in a container that has no kernel.
RUN apt-get update && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        p7zip-full \
        procps \
        nginx-light \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
        libc6-dev libcrypt-dev linux-libc-dev rpcsvc-proto \
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
