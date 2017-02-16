(function () {
	var password = window.location.hash.slice(1) || null;
	var secret = null;

	function switchModal(name)
	{
		var mods = document.querySelectorAll('.md');
		[].forEach.call(mods, function(mod){ mod.className = 'md'; });
		document.getElementById('md_' + name).className += ' open';
	}

	function decryptSecret(secret, password)
	{
		if (!secret)
		{
			switchModal('error');
			return;
		}

		// Derivating key from password
		var key = sodium.crypto_pwhash_scryptsalsa208sha256(
			sodium.crypto_secretbox_KEYBYTES,
			password,
			sodium.from_hex(secret.salt),
			sodium.crypto_pwhash_scryptsalsa208sha256_OPSLIMIT_INTERACTIVE,
			sodium.crypto_pwhash_scryptsalsa208sha256_MEMLIMIT_INTERACTIVE
		);

		try {
			// Decrypt secret
			var data = sodium.crypto_secretbox_open_easy(
				sodium.from_hex(secret.text),
				sodium.from_hex(secret.nonce),
				key,
				'text'
			);
		}
		catch (err)
		{
			var data = false;
		}

		if (data)
		{
			switchModal('secret');
			var secret = document.getElementById('secret');
			secret.value = data;
			secret.select();
			secret.focus();

			document.querySelector('.copy').onclick = function () {
				secret.select();
				document.execCommand('copy');
				return false;
			};
		}
		else
		{
			switchModal('password');
			document.getElementById('wrong_password').style.display = 'block';
		}

		return data;
	}

	window.secretQuickie = function () {
		var password = window.location.hash.slice(1) || document.getElementById('password').value;

		switchModal('wait');

		if (secret)
		{
			decryptSecret(secret, password);
			return null;
		}

		var xhr = new XMLHttpRequest;
		xhr.open('POST', document.forms[0].action, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

		xhr.onreadystatechange = function() {
			if(xhr.readyState == 4 && xhr.status == 200) {
				secret = JSON.parse(xhr.responseText);
				decryptSecret(secret, password);
			}
		}

		var token = document.querySelector('input[type=hidden]');
		var data = token.name + '=' + token.value;

		xhr.send(data);

		return false;
	};


	switchModal(password ? 'confirm' : 'password');
}());