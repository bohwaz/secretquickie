<?php

namespace SecretQuickie;

use KD2\OpenIDConnect;

require __DIR__ . '/../../bootstrap.php';

$oid = new OpenIDConnect(
	OPENID_URL,
	OPENID_CLIENT_ID,
	OPENID_CLIENT_SECRET,
	APP_URL . 'auth/'
);

if ($oid->authenticate())
{
	$info = $oid->getUserInfo();

	if (OPENID_EMAIL_WHITELIST)
	{
		$whitelist = preg_quote(OPENID_EMAIL_WHITELIST, '/');
		$whitelist = str_replace('\*', '.*?', $whitelist);

		if (!preg_match('/' . $whitelist . '/', $info->email))
		{
			var_dump($whitelist);
			die('Not allowed');
		}
	}

	$cookie->set('user', 'logged');
	$cookie->save();

	die('ok');
}
else
{
	die('Fail');
}