<?php

namespace SecretQuickie;

use \KD2\Form;

require __DIR__ . '/../../bootstrap.php';

function is_posting($api = false)
{
	if (!Form::tokenCheck('post'))
	{
		return false;
	}

	if (empty($_POST['expiry']) || !is_numeric($_POST['expiry']))
	{
		return false;
	}

	if ($api)
	{
		return !empty($_POST['nonce']) && !empty($_POST['salt']) && !empty($_POST['text']);
	}
	else
	{
		return !empty($_POST['secret']);
	}
}

if (!$user->isLogged() && REQUIRE_OPENID)
{
	$tpl->assign('error', sprintf('Please login with %s to be able to create new secrets.', OPENID_NAME));
	$tpl->display('error.tpl');
	exit;
}

if (!empty($_GET['js']))
{
	if (!is_posting(true))
	{
		$response = ['error' => 'Invalid form'];
		$response['debug'] = $_POST;
	}
	else
	{
		$sq = new SecretQuickie;
		$uri = $sq->storeEncrypted($_POST['text'], $_POST['nonce'], $_POST['salt'], (int)$_POST['expiry']);
		$response = ['url' => APP_URL . '?' . $uri];
	}

	echo json_encode($response);
	exit;
}

if (!empty($_POST['post']) && !empty($_POST['secret']) && Form::tokenCheck('post'))
{
	$data = isset($_POST['secret']) ? $_POST['secret'] : null;
	$password = !empty($_POST['password']) ? $_POST['password'] : null;
	$expiry = !empty($_POST['expiry']) ? (int) $_POST['expiry'] : 24;

	$sq = new SecretQuickie;
	$uri = $sq->store($data, $expiry, $password);

	$tpl->assign('secret_url', APP_URL . '?' . $uri);
}

$expire_table = [
	'1'  => '1 hour',
	'6'  => '6 hours',
	'24' => '24 hours',
	'48' => '2 days',
	'168' => '1 week',
	'336' => '2 weeks',
];

$tpl->assign('expire_table', $expire_table);

$tpl->assign('token', Form::tokenHTML('post'));
$tpl->display('post.tpl');