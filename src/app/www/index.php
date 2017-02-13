<?php

namespace SecretQuickie;

use \KD2\Security;

require __DIR__ . '/../bootstrap.php';

$sq = new SecretQuickie;

if (!empty($_GET['get']))
{
	$key = $_GET['get'];

	// Retrieve and burn the secret for javascript XHR
	if (Security::tokenCheck('view_js_' . $key))
	{
		$data = $sq->retrieveEncrypted($key);
	}
	else
	{
		$data = ['error' => 'Invalid token.'];
	}

	echo json_encode($data);
}
else if ($secret = $sq->findSecretInURL())
{
	$key = $secret[0];
	$password = isset($secret[1]) ? $secret[1] : false;

	if (!$password && isset($_POST['password']))
	{
		$password = $_POST['password'];
	}

	// Confirm you want to burn the secret
	if (!Security::tokenCheck('view_' . $key) && $password)
	{
		$tpl->assign('url', APP_URL . '?' . $key . '&' . $password);
		$tpl->assign('token', Security::tokenHTML('view_' . $key));
		$tpl->display('confirm.tpl');
	}
	elseif ($password)
	{
		$tpl->assign('secret', $sq->retrieve($key, $password));
		$tpl->display('secret.tpl');
	}
	else
	{
		$tpl->assign('token_js', Security::tokenHTML('view_js_' . $key));
		$tpl->assign('token', Security::tokenHTML('view_' . $key));
		$tpl->assign('secret_key', $key);
		$tpl->display('secret_js.tpl');
	}
}
else
{
	header('Location: ' . APP_URL . 'post/');
}