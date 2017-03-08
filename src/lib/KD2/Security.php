<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/
  
  Copyright (c) 2001-2016 BohwaZ <http://bohwaz.net/>
  All rights reserved.
  
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.
  2. Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
  
  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
  THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace KD2;

class Security
{
	/**
	 * Secret used for tokens
	 * @var null
	 */
	static protected $token_secret = null;

	/**
	 * Allowed schemes/protocols in URLs
	 * @var array
	 */
	static protected $whitelist_url_schemes = [
		'http'  =>  '://',
		'https' =>  '://',
		'ftp'   =>  '://',
		'mailto'=>  ':',
		'xmpp'  =>  ':',
		'news'  =>  ':',
		'nntp'  =>  '://',
		'tel'   =>  ':',
		'callto'=>  ':',
		'ed2k'  =>  '://',
		'irc'   =>  '://',
		'magnet'=>  ':',
		'mms'   =>  '://',
		'rtsp'  =>  '://',
		'sip'   =>  ':',
	];

	/**
	 * Timing attack safe string comparison (shim, works with PHP < 5.6)
	 *
	 * Compares two strings using the same time whether they're equal or not.
	 * This function should be used to mitigate timing attacks.
	 * 
	 * @link https://secure.php.net/manual/en/function.hash-equals.php
	 * 
	 * @param  string $known_string The string of known length to compare against
	 * @param  string $user_string  The user-supplied string
	 * @return boolean              
	 */
	static public function hash_equals($known_string, $user_string)
	{
		$known_string = (string) $known_string;
		$user_string = (string) $user_string;
		
		// For PHP 5.6/PHP 7 use the native function
		if (function_exists('hash_equals'))
		{
			return hash_equals($known_string, $user_string);
		}

		$ret = strlen($known_string) ^ strlen($user_string);
		$ret |= array_sum(unpack("C*", $known_string^$user_string));
		return !$ret;
	}

	/**
	 * Generates a random number between $min and $max
	 *
	 * The number will be crypto secure unless you set $insecure_fallback to TRUE,
	 * then it can provide a insecure number if no crypto source is available.
	 *
	 * @link https://codeascraft.com/2012/07/19/better-random-numbers-in-php-using-devurandom/
	 * @param  integer $min               Minimum number
	 * @param  integer $max               Maximum number
	 * @param  boolean $insecure_fallback Set to true to fallback to mt_rand()
	 * @return integer                    A random number
	 * @throws Exception If no secure random source is found and $insecure_fallback is set to false
	 */
	static public function random_int($min = 0, $max = PHP_INT_MAX, $insecure_fallback = false)
	{
		// Only one possible value, not random
		if ($max == $min)
		{
			return $min;
		}

		if ($min > $max)
		{
			throw new \Exception('Minimum value must be less than or equal to to the maximum value');
		}

		// Use the native PHP function for PHP 7+
		if (function_exists('random_int'))
		{
			return random_int($min, $max);
		}

		try {
			// Get some random bytes
			$bytes = self::random_bytes(PHP_INT_SIZE);
		}
		catch (\Exception $e)
		{
			// No crypto random found

			// For trivial stuff we can just use mt_rand() instead
			if ($insecure_fallback)
			{
				return mt_rand($min, min($max, mt_getrandmax()));
			}

			// But for crypto stuff you should expect this to fail
			throw $e;
		}

		// 64-bits
		if (PHP_INT_SIZE == 8)
		{
			list($higher, $lower) = array_values(unpack('N2', $bytes));
			$value = $higher << 32 | $lower;
		}
		// 32 bits
		else
		{
			list($value) = array_values(unpack('Nint', $bytes));
		}

		$value = $value & PHP_INT_MAX;
		$value = (float) $value / PHP_INT_MAX; // convert to [0,1]
		return (int) (round($value * ($max - $min)) + $min);
	}

	/**
	 * Returns a specified number of cryptographically secure random bytes
	 * @param  integer $length Number of bytes to return
	 * @return string Random bytes
	 * @throws Exception If an appropriate source of randomness cannot be found, an Exception will be thrown.
	 */
	static public function random_bytes($length)
	{
		$length = (int) $length;

		if (function_exists('random_bytes'))
		{
			return random_bytes($length);
		}

		if (function_exists('mcrypt_create_iv'))
		{
			return mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);
		} 

		if (file_exists('/dev/urandom') && is_readable('/dev/urandom'))
		{
			return file_get_contents('/dev/urandom', false, null, 0, $length);
		}

		if (function_exists('openssl_random_pseudo_bytes'))
		{
			return openssl_random_pseudo_bytes($length);
		}

		throw new \Exception('An appropriate source of randomness cannot be found.');
	}

	/**
	 * Sets the secret key used to hash and check the CSRF tokens
	 * @param  string $secret Whatever secret you may like, must be the same for all the user session
	 * @return boolean true
	 */
	static public function tokenSetSecret($secret)
	{
		self::$token_secret = $secret;
		return true;
	}

	/**
	 * Generate a single use token and return the value
	 * The token will be HMAC signed and you can use it directly in a HTML form
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @param  integer $expire Number of hours before the hash will expire
	 * @return string         HMAC signed token
	 */
	static public function tokenGenerate($action = null, $expire = 5)
	{
		if (is_null(self::$token_secret))
		{
			throw new \RuntimeException('No CSRF token secret has been set.');
		}

		// Default action, will work as long as the check is on the same URI as the generation
		if (is_null($action) && !empty($_SERVER['REQUEST_URI']))
		{
			$url = parse_url($_SERVER['REQUEST_URI']);

			if (!empty($url['path']))
			{
				$action = $url['path'];
			}
		}

		$random = self::random_int();
		$expire = floor(time() / 3600) + $expire;
		$value = $expire . $random . $action;

		$hash = hash_hmac('sha256', $expire . $random . $action, self::$token_secret);

		return $hash . '/' . dechex($expire) . '/' . dechex($random);
	}

	/**
	 * Checks a CSRF token
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @param  string $value  User supplied value, if NULL then $_POST[automatic name] will be used
	 * @return boolean
	 */
	static public function tokenCheck($action = null, $value = null)
	{
		if (is_null($value))
		{
			$name = 'ct_' . sha1($action . $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SERVER_NAME']);
			
			if (empty($_POST[$name]))
			{
				return false;
			}

			$value = $_POST[$name];
		}

		$value = explode('/', $value, 3);

		if (count($value) != 3)
		{
			return false;
		}

		$user_hash = $value[0];
		$expire = hexdec($value[1]);
		$random = hexdec($value[2]);

		// Expired token
		if ($expire < ceil(time() / 3600))
		{
			return false;
		}

		$hash = hash_hmac('sha256', $expire . $random . $action, self::$token_secret);

		return self::hash_equals($hash, $user_hash);
	}

	/**
	 * Returns HTML code to embed a CSRF token in a form
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @return string HTML <input type="hidden" /> element
	 */
	static public function tokenHTML($action = null)
	{
		$name = 'ct_' . sha1($action . $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SERVER_NAME']);
		return '<input type="hidden" name="' . $name . '" value="' . self::tokenGenerate($action) . '" />';
	}

	/**
	 * Check an email address validity
	 * @param  string $email Email address
	 * @return boolean TRUE if valid
	 */
	static public function checkEmailAddress($email)
	{
		return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
	}

	/**
	 * Check an URL validity (scheme and host are required, '//domain.com/uri' will be invalid for example)
	 * @param  string $url URL to check
	 * @return boolean TRUE if valid
	 */
	static public function checkURL($url)
	{
		return (bool) filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED);
	}

	/**
	 * Returns a random password of $length characters, picked from $alphabet
	 * @param  integer $length  Length of password
	 * @param  string $alphabet Alphabet used for password generation
	 * @return string
	 */
	static public function getRandomPassword($length = 12, $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ123456789=/:!?-_')
	{
		$password = '';

		for ($i = 0; $i < (int)$length; $i++)
		{
			$pos = self::random_int(0, strlen($alphabet) - 1);
			$password .= $alphabet[$pos];
		}

		return $password;
	}

	/**
	 * Returns a random passphrase of $words length
	 *
	 * You can use any dictionary from /usr/share/dict, or any text file with one word per line
	 * 
	 * @param  string  $dictionary      Path to dictionary file
	 * @param  integer $words           Number of words to include
	 * @param  boolean $character_match Regexp (unicode) character class to match, eg.
	 * if you want only words in lowercase: \pL
	 * @param  boolean $add_entropy     If TRUE will replace one character from each word randomly with a number or special character
	 * @return string Passphrase
	 */
	static public function getRandomPassphrase($dictionary = '/usr/share/dict/words', $words = 4, $character_match = false, $add_entropy = false)
	{
		if (empty($dictionary) || !is_readable($dictionary))
		{
			throw new \InvalidArgumentException('Invalid dictionary file: cannot open or read from file \'' . $dictionary . '\'');
		}

		$file = file($dictionary);
		
		$selection = [];
		$max = 1000;
		$i = 0;

		while (count($selection) < (int) $words)
		{
			if ($i++ > $max)
			{
				throw new \Exception('Could not find a suitable combination of words.');
			}

			$rand = self::random_int(0, count($file) - 1);
			$w = trim($file[$rand]);

			if (!$character_match || preg_match('/^[' . $character_match . ']+$/U', $w))
			{
				if ($add_entropy)
				{
					$w[self::random_int(0, strlen($w) - 1)] = self::getRandomPassword(1, '23456789=/:!?-._');
				}

				$selection[] = $w;
			}
		}

		return implode(' ', $selection);
	}

	/**
	 * Protects a URL/URI given as an image/link target against XSS attacks
	 * (at least it tries)
	 * @param  string 	$value 	Original URL
	 * @return string 	Filtered URL but should still be escaped, like with htmlspecialchars for HTML documents
	 */
	static public function protectURL($value)
	{
		// Decode entities and encoded URIs
		$value = rawurldecode($value);
		$value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

		// Convert unicode entities back to ASCII
		// unicode entities don't always have a semicolon ending the entity
		$value = preg_replace_callback('~&#x0*([0-9a-f]+);?~i', 
			function($match) { return chr(hexdec($match[1])); }, 
			$value);
		$value = preg_replace_callback('~&#0*([0-9]+);?~', 
			function ($match) { return chr($match[1]); },
			$value);

		// parse_url already helps against some XSS malformed URLs
		$url = parse_url($value);

		// This should not happen as parse_url can usually deal with most malformed URLs
		if (!$url)
		{
			return false;
		}

		$value = '';

		if (!empty($url['scheme']))
		{
			$url['scheme'] = strtolower($url['scheme']);

			if (!array_key_exists($url['scheme'], self::$whitelist_url_schemes))
			{
				return '';
			}

			$value .= $url['scheme'] . self::$whitelist_url_schemes[$url['scheme']];
		}

		if (!empty($url['user']))
		{
			$value .= rawurlencode($url['user']);

			if (!empty($url['pass']))
			{
				$value .= ':' . rawurlencode($url['pass']);
			}

			$value .= '@';
		}

		if (!empty($url['host']))
		{
			$value .= $url['host'];
		}

		if (!empty($url['port']) && !($url['scheme'] == 'http' && $url['port'] == 80) 
			&& !($url['scheme'] == 'https' && $url['port'] == 443))
		{
			$value .= ':' . (int) $url['port'];
		}

		if (!empty($url['path']))
		{
			// Split and re-encode path
			$url['path'] = explode('/', $url['path']);
			$url['path'] = array_map('rawurldecode', $url['path']);
			$url['path'] = array_map('rawurlencode', $url['path']);
			$url['path'] = implode('/', $url['path']);

			// Keep leading /~ un-encoded for compatibility with user accounts on some web servers
			$url['path'] = preg_replace('!^/%7E!', '/~', $url['path']);

			$value .= $url['path'];
		}

		if (!empty($url['query']))
		{
			// We can't use parse_str and build_http_string to sanitize url here
			// Or else we'll get things like ?param1&param2 transformed in ?param1=&param2=
			$query = explode('&', $url['query'], 2);

			foreach ($query as &$item)
			{
				$item = explode('=', $item);

				if (isset($item[1]))
				{
					$item = rawurlencode(rawurldecode($item[0])) . '=' . rawurlencode(rawurldecode($item[1]));
				}
				else
				{
					$item = rawurlencode(rawurldecode($item[0]));
				}
			}

			$value .= '?' . implode('&', $query);
		}

		if (!empty($url['fragment']))
		{
			$value .= '#' . rawurlencode(rawurldecode($url['fragment']));
		}
		
		return $value;
	}

	/**
	 * Check that GnuPG extension is installed and available to encrypt emails
	 * @return boolean
	 */
	static public function canUseEncryption()
	{
		return (extension_loaded('gnupg') && function_exists('\gnupg_init') && class_exists('\gnupg', false));
	}

	/**
	 * Initializes gnupg environment and object
	 * @param  string $key     Public encryption key
	 * @param  string &$tmpdir Temporary directory used to store gnupg keys
	 * @param  array  &$info   Informations about the imported key
	 * @return \gnupg
	 */
	static protected function _initGnupgEnv($key, &$tmpdir, &$info)
	{
		if (!self::canUseEncryption())
		{
			throw new \RuntimeException('Cannot use encryption: gnupg extension not found.');
		}

		$tmpdir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('gpg_', true);

		// Create temporary home directory as required by gnupg
		mkdir($tmpdir);

		if (!is_dir($tmpdir))
		{
			throw new \RuntimeException('Cannot create temporary directory for GnuPG');
		}

		putenv('GNUPGHOME=' . $tmpdir);
		
		$gpg = new \gnupg;
		$gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

		$info = $gpg->import($key);

		return $gpg;
	}

	/**
	 * Cleans gnupg environment
	 * @param  string $tmpdir Temporary directory used to store gpg keys
	 * @return void
	 */
	static protected function _cleanGnupgEnv($tmpdir)
	{
		// Remove files
		array_map('unlink', glob($tmpdir . DIRECTORY_SEPARATOR . '*') ?: []);
		rmdir($tmpdir);
	}

	/**
	 * Returns pgp key fingerprint
	 * @param  string $key Public key
	 * @return string Fingerprint
	 */
	static public function getEncryptionKeyFingerprint($key)
	{
		self::_initGnupgEnv($key, $tmpdir, $info);
		self::_cleanGnupgEnv($tmpdir);

		return $info['fingerprint'];
	}

	/**
	 * Encrypt clear text data with GPG public key
	 * @param  string  $key    Public key
	 * @param  string  $data   Data to encrypt
	 * @param  boolean $binary set to false to have the function return armored string instead of binary
	 * @return string
	 */
	static public function encryptWithPublicKey($key, $data, $binary = false)
	{
		$gpg = self::_initGnupgEnv($key, $tmpdir, $info);

		$gpg->setarmor((int)!$binary);
		$gpg->addencryptkey($info['fingerprint']);
		$data = $gpg->encrypt($data);

		self::_cleanGnupgEnv($tmpdir);

		return $data;
	}
}