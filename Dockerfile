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

RUN php /app/bin/cppc --emit-object /app/runtime/runtime_compat.asm -o /app/runtime/runtime_compat.o

ENTRYPOINT ["php"]
