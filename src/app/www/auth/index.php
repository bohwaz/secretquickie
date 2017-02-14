<?php

namespace SecretQuickie;

use KD2\OpenIDConnect;

require __DIR__ . '/../../bootstrap.php';

try {
	$user->OpenIDLogin();
	header('Location: ' . APP_URL . 'post/');
}
catch (\RuntimeException $e)
{
	$tpl->assign('error', $e->getMessage());
	$tpl->display('error.tpl');
}
