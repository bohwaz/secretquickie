<?php

namespace SecretQuickie;

use \KD2\Form;

require __DIR__ . '/../bootstrap.php';

$sq = new SecretQuickie;

if (!empty($_GET['get']))
{
	$key = $_GET['get'];

	// Retrieve and burn the secret for javascript XHR
	if (Form::tokenCheck('view_js_' . $key))
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
	if (!Form::tokenCheck('view_' . $key) && $password)
	{
		$tpl->assign('url', APP_URL . '?' . $key . '&' . $password);
		$tpl->assign('token', Form::tokenHTML('view_' . $key));
		$tpl->assign('password', (bool) $password);
		$tpl->display('confirm.tpl');
	}
	elseif ($password)
	{
		$tpl->assign('url', APP_URL . '?' . $key);
		$tpl->assign('secret', $sq->retrieve($key, $password));
		$tpl->display('secret.tpl');
	}
	else
	{
		$tpl->assign('token_js', Form::tokenHTML('view_js_' . $key));
		$tpl->assign('token', Form::tokenHTML('view_' . $key));
		$tpl->assign('secret_key', $key);
		$tpl->display('secret_js.tpl');
	}
}
else
{
	header('Location: ' . APP_URL . 'post/');
}