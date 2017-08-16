
# This is fork of SUPLA-CLOUD

Supla-Cloud require Apache 2.x, PHP 7.x and MySQL.

Installation on Debian 9
========================

    curl -sL https://deb.nodesource.com/setup_6.x | sudo -E bash -
    sudo apt-get install -y nodejs

    git clone https://github.com/IoTAqua/supla-cloud.git
    cd supla-cloud

    cat supla-db.sql | mysql -p -u root
    mysql -p -u root
    * CREATE USER 'supla'@'localhost' IDENTIFIED BY '<mysql-supla-password>';
    * GRANT ALL PRIVILEGES ON supla.* To 'supla'@'localhost';
    * FLUSH PRIVILEGES;

    vi app/config/parameters.yml
    * set "database_password:" <mysql-supla-password>
    * set "mailer_from:" <admin@domain>
    * set "supla_server:" <supla server address>
    * set "ewz_recaptcha_public_key:" Site key from www.google.com/recaptcha
    * set "ewz_recaptcha_private_key:" Secret key from www.google.com/recaptcha

    curl -sS https://getcomposer.org/installer | php
    php composer.phar install --no-dev --optimize-autoloader
    php composer.phar run-script webpack
    php bin/console doctrine:schema:update --force
    php bin/console cache:clear --env=prod

    sudo cp -R ../supla-cloud /var/www/
    sudo chown -R root:www-data /var/www/supla-cloud
    sudo chown -R www-data:www-data /var/www/supla-cloud/var
    sudo chmod 640 /var/www/supla-cloud/app/config/*

Setup Apache
============

Config must have:

    DocumentRoot /var/www/supla-cloud/web
    Directory /var/www/supla-cloud/web>
      AllowOverride All
      Order Allow,Deny
      Allow from All
    </Directory>
