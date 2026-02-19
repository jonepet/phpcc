FROM php:8.5-cli

RUN apt-get update && apt-get install -y \
    nasm \
    gcc \
    g++ \
    binutils \
    libc6-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json /app/
RUN composer install --no-dev --optimize-autoloader --no-scripts

COPY . /app/
RUN composer dump-autoload --optimize --no-dev

RUN gcc -c -o /app/runtime/runtime_compat.o /app/runtime/runtime_compat.c

ENTRYPOINT ["php"]
