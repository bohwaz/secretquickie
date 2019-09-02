define js_hashes
	echo -n > js_hashes.txt
	for i in app/www/static/*.js
	do
		cat "$i" | openssl dgst -sha384 -binary | openssl enc -base64 -A >> js_hashes.txt
		echo " $(basename "$i")" >> js_hashes.txt
	done
endef

all:

js_hashes.txt: app/www/static/*.js; @$(value js_hashes)

test:
	php -d zend.assertions=1 -d assert.exception=1 -d assert.active=1 -d apc.enable_cli=1 tests/*

.ONESHELL:

.PHONY: test all