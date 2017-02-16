<?php

namespace SecretQuickie;

use KD2\CacheCookie;
use KD2\OpenIDConnect;

class User
{
	/**
	 * Session duration, in minutes
	 * 129600 = 60 minutes * 24 hours * 90 days
	 */
	const SESSION_DURATION = 129600;

	/**
	 * CacheCookie object
	 * @var CacheCookie
	 */
	protected $cookie;

	/**
	 * Initiates session
	 */
	public function __construct()
	{
		$this->cookie = new CacheCookie('session', APP_SECRET, self::SESSION_DURATION);
	}

	/**
	 * Returns true if a user is logged
	 * @return boolean
	 */
	public function isLogged()
	{
		return $this->cookie->get('user') === 'logged';
	}

	/**
	 * Process OpenID Connect login with provider
	 * @return boolean
	 */
	public function OpenIDLogin()
	{
		$oid = new OpenIDConnect(
			OPENID_URL,
			OPENID_CLIENT_ID,
			OPENID_CLIENT_SECRET,
			APP_URL . 'auth/'
		);

		// authenticate with openid connect
		if (!$oid->authenticate())
		{
			return false;
		}

		// fetch user info
		$info = $oid->getUserInfo();

		if (empty($info->email))
		{
			throw new \RuntimeException('No email supplied in OpenID response.');
		}

		// Check that the email is allowed
		if (OPENID_EMAIL_WHITELIST)
		{
			if (!preg_match('/' . OPENID_EMAIL_WHITELIST . '/', $info->email))
			{
				throw new \RuntimeException('E-mail address is not whitelisted. Access denied.');
			}
		}

		// Store in session
		$this->cookie->set('user', 'logged');
		$this->cookie->save();

		return true;
	}
}