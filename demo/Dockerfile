# Supertext Translation for Craft CMS: public demo (Railway). Build context = repository root:
#   docker build -f demo/Dockerfile -t supertext-craft-demo .
FROM mirror.gcr.io/library/php:8.5-apache

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl gd zip bcmath opcache \
 && (a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true) \
 && a2enmod mpm_prefork rewrite headers \
 && printf 'memory_limit=256M\nmax_execution_time=300\nupload_max_filesize=16M\npost_max_size=16M\n' > /usr/local/etc/php/conf.d/craft.ini \
 && sed -ri 's#/var/www/html#/app/demo/project/web#g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && printf '<Directory /app/demo/project/web>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/craft.conf

COPY --from=mirror.gcr.io/library/composer:2 /usr/bin/composer /usr/bin/composer

# The plugin (repository root) and the demo project, which requires it through a path repository.
WORKDIR /app
COPY composer.json LICENSE README.md CHANGELOG.md /app/
COPY src /app/src
COPY lib /app/lib
COPY demo /app/demo
WORKDIR /app/demo/project
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --prefer-dist \
 && chown -R www-data:www-data storage web config

ENV CRAFT_ALLOW_ADMIN_CHANGES=true CRAFT_DEV_MODE=false PORT=8080
EXPOSE 8080
CMD ["/app/demo/docker/entrypoint.sh"]
