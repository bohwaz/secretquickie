# SecretQuickie — One Time Secret web app

This small web app stores a secret text, encrypts it, and will delete it the first it is viewed.

This is similar to what [One Time Secret.com](https://github.com/onetimesecret/onetimesecret) is doing. Except this is a standalone PHP application, with solid client-side and server-side encryption.

## Features

* Stores secrets encrypted in memory
* Configurable secret expiry
* Optional OpenID Connect login (Microsoft, Google, Amazon, eBay, Yahoo, Paypal, etc.)
* Secrets are encrypted and decrypted by the client if javascript is enabled, never transmitting the password over the internet
* Secrets are always stored encrypted, there is no way to decrypt any secret without the user password
* Doesn't use any network communication like MySQL, memcache or Redis: secrets are never transmitted over any network
* Secrets are 100% anonymous, no IP or user info is stored
* Secrets will disppear if the server is shutdown, stolen, etc.
* If the client has disabled javascript, encryption/decryption will happen on server side (fallback)
* Uses APCu for memory storage
* Uses php-libsodium and libsodium.js for cryptography
* Uses [SubResource Integrity (SRI)](https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity) to load javascript resources, adding extra security to client-side encryption, see for details.

## Requirements

* PHP 5.6+
* PHP APCu extension
* PHP libsodium extension

## Installation of dependencies (Debian/Ubuntu)

You will need PHP (5.6 or 7+), and then:

For PHP 5:

	# apt-get install php5-apcu

For PHP 7:

	# apt-get install php-apcu

For Ubuntu 16.10+ / Debian Stretch+:

	# apt-get install php-libsodium

For Ubuntu < 16.10 and Debian Wheezy/Jessie:

	# apt-get install libsodium-dev
	# pecl install libsodium
	# echo "extension=libsodium.so" > /etc/php5/mods-available/libsodium.ini
	# for DIR in /etc/php5/{apache2,cli,fpm}/conf.d; do ln -s "../../mods-available/libsodium.ini" $DIR/20-libsodium.ini; done;

## Installation of SecretQuickie

* Set up a new virtual host to use the directory app/www as its document root.
* Copy the .env.example to .env and edit it to suit your needs

## Credits

* Uses [libsodium.js](https://github.com/jedisct1/libsodium.js) and php-libsodium from Frank Denis and Scott Arciszewski
* CSS framework is [picnic.css](https://picnicss.com/)