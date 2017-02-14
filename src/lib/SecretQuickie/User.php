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

	public function __construct()
	{
		$this->cookie = new CacheCookie('session', APP_SECRET, self::SESSION_DURATION);
	}

	public function isLogged()
	{
		return $this->cookie->get('user') === 'logged';
	}

	public function OpenIDLogin()
	{
		$oid = new OpenIDConnect(
			OPENID_URL,
			OPENID_CLIENT_ID,
			OPENID_CLIENT_SECRET,
			APP_URL . 'auth/'
		);

		if (!$oid->authenticate())
		{
			return false;
		}

		$info = $oid->getUserInfo();

		if (empty($info->email))
		{
			throw new \RuntimeException('No email supplied in OpenID response.');
		}

		if (OPENID_EMAIL_WHITELIST)
		{
			$whitelist = preg_quote(OPENID_EMAIL_WHITELIST, '/');
			$whitelist = str_replace('\*', '.*?', $whitelist);

			if (!preg_match('/' . $whitelist . '/', $info->email))
			{
				throw new \RuntimeException('E-mail address is not whitelisted. Access denied.');
			}
		}

		$this->cookie->set('user', 'logged');
		$this->cookie->save();

		return true;
	}
}