FROM php:8.2-apache

# Ekstensi PHP yang dibutuhkan aplikasi ini (PDO MySQL untuk api/config.php,
# mbstring dipakai ai_talent_match.php untuk tokenisasi teks multi-byte).
RUN apt-get update && apt-get install -y \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql mysqli mbstring
RUN docker network create -d bridge mybridge

# Timezone Indonesia (WIB) -- proyek ini menghitung tanggal deadline/jadwal.
RUN ln -snf /usr/share/zoneinfo/Asia/Jakarta /etc/localtime \
    && echo "date.timezone=Asia/Jakarta" > /usr/local/etc/php/conf.d/timezone.ini

# database/ dan tests/ dilindungi lewat .htaccess (Require all denied) --
# AllowOverride default Apache/Debian untuk /var/www adalah "None", jadi
# .htaccess itu tidak akan pernah dibaca kecuali diaktifkan di sini.
RUN { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/allow-override.conf \
    && a2enconf allow-override \
    && a2enmod rewrite

WORKDIR /var/www/html

