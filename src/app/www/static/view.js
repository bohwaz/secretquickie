(function () {
	var password = window.location.hash.slice(1) || false;
	var secret = null;

	function switchModal(name)
	{
		var mods = document.querySelectorAll('.modal > [type=checkbox]');
		[].forEach.call(mods, function(mod){ mod.checked = false; });

		if (name)
		{
			document.getElementById('modal_' + name).checked = true;

			if (name == 'password' || name == 'confirm')
			{
				document.querySelector('.' + name).focus();
			}
		}
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
			document.getElementById('wrong_password').className = '';

			document.querySelector('.password').onkeyup = function () {
				document.getElementById('wrong_password').className = 'hidden';
			};
		}

		return data;
	}

	function secretQuickie () {
		var password = window.location.hash.slice(1) || document.querySelector('.password').value;

		switchModal('wait');

		if (secret)
		{
			decryptSecret(secret, password);
			return false;
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
	}

	switchModal(!password ? 'password' : 'confirm');
	document.querySelector(!password ? '.decrypt' : '.confirm').onclick = function () { return secretQuickie(); };
	document.forms[0].onsubmit = function () { console.log('sub'); return secretQuickie(); };
}());