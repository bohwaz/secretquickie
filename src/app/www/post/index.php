<?php

namespace SecretQuickie;

use \KD2\Security;

require __DIR__ . '/../../bootstrap.php';

if (!$user->isLogged() && REQUIRE_OPENID)
{
	$tpl->assign('error', sprintf('Please login with %s to be able to create new secrets.', OPENID_NAME));
	$tpl->display('error.tpl');
	exit;
}

if (!empty($_POST['post']) && Security::tokenCheck('post'))
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

$tpl->assign('token', Security::tokenHTML('post'));
$tpl->display('post.tpl');