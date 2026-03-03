FROM wordpress:6.8-php8.2-apache

# Needed by the plugin for PDF extraction.
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/*
