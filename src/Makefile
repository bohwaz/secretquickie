define js_hashes
	echo -n > js_hashes.txt
	for i in app/www/static/*.js
	do
		cat "$i" | openssl dgst -sha384 -binary | openssl enc -base64 -A >> js_hashes.txt
		echo " $(basename "$i")" >> js_hashes.txt
	done
endef

js_hashes.txt: app/www/static/*.js; @$(value js_hashes)

.ONESHELL: