# The load target runs on the same base image production does, which is
# the whole reason it can answer anything: php-fpm and nginx tuned the
# way the fleet is tuned, opcache on, and — the part that matters here —
# the cbox_telemetry extension already built into the image.
#
# The extension ships present but NOT loaded: the image stages its ini
# files in conf.d-telemetry/ and conf.d-telemetry-auto/ rather than
# conf.d/, so enabling it is a copy. NATIVE below picks one of three
# configurations, because "what does the native profiler cost" is a
# question this rig should be able to answer and cannot if it is always
# on or always off.
ARG PHP_IMAGE=ghcr.io/cboxdk/php-baseimages/php-fpm-nginx:8.5-bookworm-dev
FROM ${PHP_IMAGE}

# off  — extension not loaded at all (the baseline)
# on   — loaded, instrumenting what the package asks it to
# auto — loaded with cbox_telemetry.auto=1, which is how a host that
#        wants everything profiled sets it
ARG NATIVE=off

RUN set -eux; \
    case "${NATIVE}" in \
      on)   cp /usr/local/etc/php/conf.d-telemetry/*.ini /usr/local/etc/php/conf.d/ ;; \
      auto) cp /usr/local/etc/php/conf.d-telemetry/*.ini /usr/local/etc/php/conf.d/; \
            cp /usr/local/etc/php/conf.d-telemetry-auto/*.ini /usr/local/etc/php/conf.d/ ;; \
      off)  : ;; \
      *)    echo "NATIVE must be off, on or auto"; exit 1 ;; \
    esac

# The files do not change under load, so stat()ing every one of them
# per request would be its own benchmark. And a pool that scales during
# the run would be measuring pm's decisions rather than the package's.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=256'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'memory_limit=512M'; \
    } > /usr/local/etc/php/conf.d/zz-loadtest.ini

RUN { \
      echo '[www]'; \
      echo 'pm = static'; \
      echo 'pm.max_children = 32'; \
      echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-loadtest.conf

# Copied, not bind-mounted. On Docker Desktop a mount crosses a VM
# boundary on every include, which cost 36ms a request here and buried
# the thing being measured — the rig has to be faster than its subject
# or it measures itself. An immutable image is also one fewer variable
# between the two halves of a run.
# Belt and braces for anyone who points PHP_IMAGE at a -dev tag: a
# profiler that is loaded and active is not a neutral observer. Xdebug
# costs per function call, the telemetry path makes many more calls than
# the baseline, and the difference lands in the delta as though it were
# the package.
RUN set -eux; \
    for ini in /usr/local/etc/php/conf.d/*xdebug*.ini; do \
      [ -e "$ini" ] || continue; \
      mv "$ini" "$ini.disabled"; \
      echo "loadtest: disabled $ini"; \
    done

COPY . /app
WORKDIR /app
