# Default build arguments (modify .env instead when "docker compose build")

# PKP_TOOL - Options are: ojs, omp, ops.
ARG PKP_TOOL=ojs                           
# PKP_VERSION - Same as PKP's versions.
ARG PKP_VERSION=3_3_0-21                   
# WEB_SERVER - Web server and PHP version
ARG WEB_SERVER=php:8.2-apache              
# WEB_USER - Web user for web server (www-data,33)
ARG WEB_USER=www-data                      
# BUILD_PKP_APP_OS - OS used to build (not run).  
ARG BUILD_PKP_APP_OS=alpine:3.22           
# BUILD_PKP_APP_PATH - Where app is built.
ARG BUILD_PKP_APP_PATH=/app                

ARG BUILD_LABEL=notset


# Stage 1: Download PKP source code from released tarball.
FROM ${BUILD_PKP_APP_OS} AS pkp_code

ARG PKP_TOOL	    	\
    PKP_VERSION		\
    BUILD_PKP_APP_OS	\
    BUILD_PKP_APP_PATH

RUN apk add --no-cache curl tar && \
    mkdir -p "${BUILD_PKP_APP_PATH}" && \
    cd "${BUILD_PKP_APP_PATH}" && \
    pkpVersion="${PKP_VERSION//_/.}" && \
    curl -sSL -O "https://pkp.sfu.ca/${PKP_TOOL}/download/${PKP_TOOL}-${pkpVersion}.tar.gz" && \
    tar --strip-components=1 -xzf "${PKP_TOOL}-${pkpVersion}.tar.gz" && \
    rm ${PKP_TOOL}-${pkpVersion}.tar.gz

# Patch for OJS 3.5.0-2 reviewer search bug
# See: https://github.com/pkp/pkp-lib/issues/12100#issuecomment-3614365262
#
# This patch updates line 256 in ${BUILD_PKP_APP_PATH}/lib/pkp/api/v1/users/PKPUserController.php
# from `'items' => $items,` to `'items' => array_values($items),`
RUN if [ "${PKP_TOOL}" = "ojs" ] && [ "${PKP_VERSION}" = "3_5_0-2" ]; then \
        sed -i '256s/^\(\s*'"'"'items'"'"'\s*=>\s*\)\$items,/\1array_values\(\$items\),/' \
        "${BUILD_PKP_APP_PATH}/lib/pkp/api/v1/users/PKPUserController.php"; \
    fi

# Stage 2: Build PHP extensions and dependencies
FROM ${WEB_SERVER} AS pkp_build

# Packages needed to build PHP extensions
ENV PKP_DEPS="\
    # Basic tools
    curl \
    unzip \
    ca-certificates \
    build-essential \
\
    # PHP extension development libraries
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libxml2-dev \
    libxslt-dev \
    libfreetype6-dev \
\
    # Modern image formats support
    libavif-dev \
\
    # Graphics/X11 support
    libxpm-dev \
    libfontconfig1-dev \
\
    # PostgreSQL development
    libpq-dev"

ENV PHP_EXTENSIONS="\
    # Image processing
    gd \
\ 
    # Internationalization
    gettext \
    intl \
\
    # String handling
    mbstring \
\
    # Database connectivity - MySQL/MariaDB
    mysqli \
    pdo_mysql \
\    
    # Database connectivity - PostgreSQL
    pgsql \
    pdo_pgsql \
\    
    # XML processing
    xml \
    xsl \
\    
    # Compression
    zip \
\
    # PKP 3.5
    bcmath \
    ftp"

RUN apt-get update && \
    apt-get upgrade -y && \
    apt-get install -y --no-install-recommends $PKP_DEPS && \
    \
    curl -sSLf https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
    -o /usr/local/bin/install-php-extensions && \
    chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions $PHP_EXTENSIONS && \
    \
    apt-get purge -y --auto-remove build-essential && \
    rm -rf /var/lib/apt/lists/*


# Stage 3: Final lightweight image
FROM ${WEB_SERVER}

ARG PKP_TOOL \
    PKP_VERSION \
    WEB_SERVER \
    WEB_USER \
    BUILD_PKP_APP_PATH \
    BUILD_LABEL

LABEL maintainer="Public Knowledge Project <marc.bria@uab.es>"
LABEL org.opencontainers.image.vendor="Public Knowledge Project"
LABEL org.opencontainers.image.title="PKP ${PKP_TOOL} Web Application"
LABEL org.opencontainers.image.version="${PKP_VERSION}"
LABEL org.opencontainers.image.revision="${PKP_TOOL}-${PKP_VERSION}#${BUILD_LABEL}"
LABEL org.opencontainers.image.description="Runs a ${PKP_TOOL} application over ${WEB_SERVER} (with rootless support)."
LABEL io.containers.rootless="true"

# Environment variables:
ENV SERVERNAME="localhost" \
    WWW_PATH_CONF="/etc/apache2/apache2.conf" \
    WWW_PATH_ROOT="/var/www" \
    HTTPS="on" \
    PKP_CLI_INSTALL="0" \
    PKP_DB_HOST="${PKP_DB_HOST:-db}" \
    PKP_DB_NAME="${PKP_DB_NAME:-pkp}" \
    PKP_DB_USER="${PKP_DB_USER:-pkp}" \
    PKP_DB_PASSWORD="${PKP_DB_PASSWORD:-changeMePlease}" \
    PKP_WEB_CONF="/etc/apache2/conf-enabled/pkp.conf" \
    PKP_CONF="config.inc.php" \
    PKP_CMD="/usr/local/bin/pkp-start"

ENV PKP_RUNTIME_LIBS="\
    # Core libraries
    libxml2 \
    libxslt1.1 \
    libicu-dev \
    libzip-dev \
\ 
    # Image processing
    libjpeg62-turbo \
    libpng16-16 \
    libfreetype6 \
    libonig-dev \
    libavif-dev \
    libwebp-dev \
\
    # Graphics/X11 support
    libxpm4 \
    libfontconfig1 \
    libx11-6 \
\
    # PostgreSQL runtime
    libpq5"

ENV PKP_APPS="\
    # If we like cron in the container (under discussion at #179)
    cron \
\
    # PDF support: pdf2text
    poppler-utils \
\
    # PostScript support: ps2acii
    ghostscript \
\
    # Word suport: antiword
    antiword "

# Updates the OS and Installs required apps and runtime libraries
RUN apt-get update && \
    apt-get upgrade -y && \
    apt-get install -y $PKP_APPS $PKP_RUNTIME_LIBS && \
    \
    apt-get purge -y --auto-remove build-essential && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*


# Copy PHP extensions and configs from build stage
COPY --from=pkp_build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=pkp_build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=pkp_build /usr/local/bin/install-php-extensions /usr/local/bin/install-php-extensions

# Set working directory
WORKDIR ${WWW_PATH_ROOT}/html

# Copy source code and configuration files
COPY --from=pkp_code "${BUILD_PKP_APP_PATH}" .
COPY "templates/pkp/root/" /
COPY "volumes/config/apache.pkp.conf" "${PKP_WEB_CONF}"

# ====================================================================
# Assumptions:
# - We have a container mounted at /var/www/public for public files
# - We have a container mounted at /var/www/files for uploaded files
# - We have a container mounted at /var/log/apache2 for logs
# - We have a container mounted at /var/www/config where a file named
#   pkp.config.inc.php is stored with the configuration settings, and
#   a file named apache.htaccess is stored with Apache configuration
#   overrides.
# - We will set this behind a service that handles TLS termination.
# - We will create separate containers for the web server and for
#   running scheduled tasks (cron jobs).

# Final configuration steps:
# - Enable apache modules (rewrite)
# - Redirect errors to stderr.
# - Link the config.inc.php and .htaccess files
# - Create container.version file
RUN a2enmod rewrite && \
    \
    echo "log_errors = On" >> /usr/local/etc/php/conf.d/log-errors.ini && \
    echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/log-errors.ini && \
    \
    ln -sf /var/www/config/pkp.config.inc.php "${PKP_CONF}" && \
    ln -sf /var/www/config/apache.htaccess .htaccess && \
    chown -R ${WEB_USER:-33}:${WEB_USER:-33} "${WWW_PATH_ROOT}" && \
    \
    . /etc/os-release && \
    echo "${PKP_TOOL}-${PKP_VERSION} with ${WEB_SERVER} over ${ID}-${VERSION_ID} [build: $(date +%Y%m%d-%H%M%S)]" \
        > "${WWW_PATH_ROOT}/container.version" && \
    cat "${WWW_PATH_ROOT}/container.version" && \
    \
    chmod +x "${PKP_CMD}"

# ====================================================================
# The following is mostly from the Google Cloud Run PHP web app quick
# start guide. See:
# https://cloud.google.com/run/docs/quickstarts/build-and-deploy/deploy-php-service

# Configure PHP for Cloud Run.
# Precompile PHP code with opcache.
RUN docker-php-ext-install -j "$(nproc)" opcache
RUN set -ex; \
  { \
    echo "; Cloud Run enforces memory & timeouts"; \
    echo "memory_limit = -1"; \
    echo "max_execution_time = 0"; \
    echo "; File upload at Cloud Run network limit"; \
    echo "upload_max_filesize = 32M"; \
    echo "post_max_size = 32M"; \
    echo "; Configure Opcache for Containers"; \
    echo "opcache.enable = On"; \
    echo "opcache.validate_timestamps = Off"; \
    echo "; Configure Opcache Memory (Application-specific)"; \
    echo "opcache.memory_consumption = 32"; \
  } > "$PHP_INI_DIR/conf.d/cloud-run.ini"

# # Ensure the webserver has permissions to execute index.php
# RUN chown -R www-data:www-data /var/www/html

# Use the PORT environment variable in Apache configuration files.
# https://cloud.google.com/run/docs/reference/container-contract#port
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# ====================================================================
# Finish up

# Expose web ports and declare volumes
EXPOSE ${HTTP_PORT:-8080}
EXPOSE ${HTTPS_PORT:-8443}

VOLUME [ "${WWW_PATH_ROOT}/files", "${WWW_PATH_ROOT}/public" ]

# # Changing to a rootless user
# USER ${WEB_USER:-33}

# Default start command
CMD "${PKP_CMD}"
