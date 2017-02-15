(function () {
	function switchModal(name)
	{
		var mods = document.querySelectorAll('.modal > [type=checkbox]');
		[].forEach.call(mods, function(mod){ mod.checked = false; });

		if (name)
		{
			document.getElementById('modal_' + name).checked = true;
		}
	}

	document.forms[0].onsubmit = function () {
		switchModal('wait');

		var secret = document.querySelector('[name=secret]').value;
		var expiry = document.querySelector('[name=expiry]').value;
		var password = document.querySelector('[name=password]').value;

		var password_uri = '';

		if (password.length == 0)
		{
			password = sodium.randombytes_buf(8);
			password = window.btoa(password);
			password = password.replace(/[^A-Z0-9]/ig, '');
			password_uri = '#' + password;
		}

		var data = encryptSecret(secret, password);

		var xhr = new XMLHttpRequest;
		xhr.open('POST', document.forms[0].action + '?js=1', true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

		xhr.onreadystatechange = function() {
			if(xhr.readyState == 4 && xhr.status == 200) {
				var response = JSON.parse(xhr.responseText);

				if (response.error)
				{
					switchModal();
					alert(response.error);
					window.location.reload();
					return;
				}

				confirmSecret(response.url, password_uri);
			}
		}

		var token = document.querySelector('input[type=hidden]');
		data[token.name] = token.value;

		data['expiry'] = document.querySelector('[name=expiry]').value;

		var params = [];

		for (var key in data)
		{
			if (!data.hasOwnProperty(key))
			{
				continue;
			}

			params.push(key + '=' + encodeURIComponent(data[key]));
		}

		params = params.join('&').replace(/%20/g, '+');
		
		xhr.send(params);

		return false;
	};

	function confirmSecret(url, password_uri)
	{
		document.querySelector('.burn').href = url + password_uri;
		
		var input = document.querySelector('input.url');
		input.value = url + password_uri;
		input.onclick = function () { this.focus(); this.select(); };
		input.select();
		input.focus();

		switchModal('confirm');
	}

	function encryptSecret(secret, password)
	{
		if (!secret)
		{
			alert('Secret is empty.');
			return;
		}

		var salt = sodium.randombytes_buf(sodium.crypto_pwhash_scryptsalsa208sha256_SALTBYTES);

		// Derivating key from password
		var key = sodium.crypto_pwhash_scryptsalsa208sha256(
			sodium.crypto_secretbox_KEYBYTES,
			password,
			salt,
			sodium.crypto_pwhash_scryptsalsa208sha256_OPSLIMIT_INTERACTIVE,
			sodium.crypto_pwhash_scryptsalsa208sha256_MEMLIMIT_INTERACTIVE
		);

		var nonce = sodium.randombytes_buf(sodium.crypto_box_NONCEBYTES);

		// Encrypt secret
		var ciphertext = sodium.crypto_secretbox_easy(
			secret,
			nonce,
			key
		);

		var data = {
			'nonce': sodium.to_hex(nonce),
			'salt':  sodium.to_hex(salt),
			'text':  sodium.to_hex(ciphertext),
		};

		return data;
	}
}());