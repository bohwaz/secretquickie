<?php

namespace SecretQuickie;

class SecretQuickie
{
	/**
	 * Constructor, checks that we can work
	 */
	public function __construct()
	{
		if (!function_exists('\apcu_add'))
		{
			throw new \LogicException('APCu extension not found. Try: apt-get install php-apcu');
		}

		if (!ini_get('apc.enabled'))
		{
			throw new \LogicException('APCu cache is not enabled. Please check "apc.enabled" in your ini file.');
		}

		if (PHP_SAPI == 'cli' && !ini_get('apc.enable_cli'))
		{
			throw new \LogicException('APCu cache is not enabled for CLI. Please check "apc.enable_cli" in your ini file.');
		}

		if (!function_exists('\Sodium\crypto_secretbox'))
		{
			throw new \LogicException('libsodium extension not found. Try: pecl install libsodium');
		}
	}

	public function findSecretInURL()
	{
		if (empty($_SERVER['QUERY_STRING']))
		{
			return false;
		}

		$qs = explode('&', $_SERVER['QUERY_STRING']);

		return $qs;
	}

	/**
	 * Returns a new random identifier for a new secret
	 * @return string
	 */
	protected function getRandomKey($length = null)
	{
		if (!$length)
		{
			// get a random length between 10 and 32 bytes
			$length = \Sodium\randombytes_uniform(22)+10;
		}

		// generate random bytes
		$key = \Sodium\randombytes_buf($length);

		// encode to something readable
		$key = base64_encode($key);
		$key = rtrim($key, '=');
		$key = strtr($key, ['+' => '-', '/' => '_']);
		return $key;
	}

	/**
	 * Returns a hashed key from a password, suitable for cryptobox encryption
	 * @param  string $password User password
	 * @param  string $salt     Random salt
	 * @return string
	 */
	protected function getCryptoKey($password, $salt)
	{
		// Derivating a key from password
		$key = \Sodium\crypto_pwhash_scryptsalsa208sha256(
			\Sodium\CRYPTO_SECRETBOX_KEYBYTES,
			$password,
			$salt,
			\Sodium\CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE,
			\Sodium\CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE
		);

		// Erase password from memory
		\Sodium\memzero($password);
	
		return $key;
	}

	public function getRandomPassphrase($length = 4)
	{
		if (!is_readable(WORDS_DICTIONARY_FILE))
		{
			throw new \RuntimeException(sprintf('Dictionary file %s not found or not readable.', WORDS_DICTIONARY_FILE));
		}

		$file = file(WORDS_DICTIONARY_FILE);
		$max = count($file);
		$phrase = [];

		for ($i = 0; $i < $length; $i++)
		{
			$phrase[] = trim($file[\Sodium\randombytes_uniform($max)]);
		}

		return implode(' ', $phrase);
	}

	/**
	 * Store a secret in memory and returns an identifier
	 * @param  string $data     Secret to store
	 * @param  integer $expiry  Secret expiry, in hours
	 * @param  string|null $password User password, if NULL, a random password will be created and sent back
	 * @return string           Identifier, eventually with a random password, if no password has been supplied
	 */
	public function store($data, $expiry = DEFAULT_EXPIRY, $password = null)
	{
		$uri = $key = $this->getRandomKey(8);

		// Just in case we have a collision
		while (apcu_exists(APCU_PREFIX . $key))
		{
			$key = $this->getRandomKey();
		}

		// Create a random password
		if (is_null($password))
		{
			$password = $this->getRandomKey();
			$uri = $key . '&' . $password;
		}

		// Creating salt
		$salt_length = \Sodium\CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES;
		$salt = \Sodium\randombytes_buf($salt_length);

		// Derivating a key from password
		$crypto_key = $this->getCryptoKey($password, $salt);

		// Encrypt data using key
		$nonce = \Sodium\randombytes_buf(\Sodium\CRYPTO_SECRETBOX_NONCEBYTES);
		$ciphertext = \Sodium\crypto_secretbox($data, $nonce, $crypto_key);

		// Remove sensitive information from memory
		\Sodium\memzero($crypto_key);
		\Sodium\memzero($data);

		$data = [
			'text'  => $ciphertext,
			'nonce' => $nonce,
			'salt'  => $salt,
		];

		// Apply base64 encode
		$data = array_map('\Sodium\bin2hex', $data);

		$data = json_encode($data);

		// Store in memory
		apcu_add(APCU_PREFIX . $key, $data, $expiry * 60 * 60);

		return $uri;
	}

	/**
	 * Retrieve a secret using a key and password
	 * @param  string $key      Identifier key
	 * @param  string $password User or randomly generated password
	 * @return string|boolean   FALSE if the key is not found, has expired, or password is wrong
	 */
	public function retrieve($key, $password)
	{
		$data = $this->retrieveEncrypted($key, false);

		// Invalid data?!
		if (!$data)
		{
			return false;
		}

		// decode data
		$data = array_map('\Sodium\hex2bin', $data);

		// Derivating a key from password
		$crypto_key = $this->getCryptoKey($password, $data['salt']);
		
		// Decrypt data
		$data = \Sodium\crypto_secretbox_open($data['text'], $data['nonce'], $crypto_key);

		// Remove sensitive information from memory
		\Sodium\memzero($crypto_key);

		if ($data !== false)
		{
			apcu_delete(APCU_PREFIX . $key);
		}

		return $data;
	}

	public function storeEncrypted($ciphertext, $nonce, $salt, $expiry = DEFAULT_EXPIRY)
	{
		$key = $this->getRandomKey(8);

		$data = [
			'text'  => $ciphertext,
			'nonce' => $nonce,
			'salt'  => $salt,
		];

		apcu_add(APCU_PREFIX . $key, json_encode($data), $expiry * 60 * 60);

		return $key;
	}

	public function retrieveEncrypted($key, $delete = true)
	{
		if (!apcu_exists(APCU_PREFIX . $key))
		{
			return false;
		}

		// Fetch key from APCu
		$data = apcu_fetch(APCU_PREFIX . $key);
		
		if ($delete)
		{
			apcu_delete(APCU_PREFIX . $key);
		}

		return json_decode($data, true);
	}
}