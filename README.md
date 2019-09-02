# SecretQuickie — One Time Secret web app

This small web app stores a secret text, encrypts it, and will delete it the first time it is viewed.

This is similar to what [One Time Secret.com](https://github.com/onetimesecret/onetimesecret) is doing. Except this is a standalone PHP application, with solid client-side and server-side encryption.

## Features

* Stores secrets encrypted in memory
* Configurable secret expiry
* Optional OpenID Connect login (Microsoft, Google, Amazon, eBay, Yahoo, Paypal, etc.)
* Secrets are encrypted and decrypted by the client if javascript is enabled, never transmitting the password over the internet
* Secrets are always stored encrypted, there is no way to decrypt any secret without the user password
* Doesn't use any network communication like MySQL, memcache or Redis: secrets are never transmitted over any network
* Secrets are 100% anonymous, no IP or user info is stored
* Secrets will be destroyed if the server is shutdown, stolen, etc. as they are only stored in RAM
* If the client has disabled javascript, encryption/decryption will happen on server side (fallback, less secure)
* Uses APCu to store secrets in RAM
* Uses php-libsodium and libsodium.js for cryptography
* Uses [SubResource Integrity (SRI)](https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity) to load javascript resources, adding extra security to client-side encryption, see for details.

## Requirements

* PHP 7.1+
* PHP APCu extension
* PHP libsodium extension

## Installation of dependencies (Debian/Ubuntu)

You will need PHP, libsodium and APCu.

	# apt-get install php-apcu

Sodium should be already included in the PHP distribution.

## Installation of SecretQuickie

* Set up a new virtual host to use the directory `app/www` as its document root.
* Copy the `.env.example` to `.env` and edit it to suit your needs

## ChangeLog

* 0.1.0, initial release, works with PHP 5.6 and 7.0 with the libsodium PECL
* 0.2.0, updated to work with PHP 7.1+ and integrated libsodium

## Credits

* Uses [libsodium.js](https://github.com/jedisct1/libsodium.js) and php-libsodium from Frank Denis and Scott Arciszewski
* CSS framework is [picnic.css](https://picnicss.com/)