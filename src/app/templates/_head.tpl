<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
	<link rel="stylesheet" type="text/css" href="{$config.APP_URL}static/picnic.min.css" />
	<link rel="stylesheet" type="text/css" href="{$config.APP_URL}static/style.css" />
	<link rel="icon" href="data:;base64,iVBORw0KGgo=" /> {* Prevents favicon requests *}
	<title>{$config.APP_NAME}</title>
</head>

<body>

<nav>
	<a href="{$config.APP_URL}" class="brand">
		<span>
			{if $config.APP_LOGO}<img src="{$config.APP_URL}static/user/{$config.APP_LOGO}" alt="{$config.APP_NAME}" />{/if}
			{$config.APP_NAME}</span>
	</a>

	{if $config.REQUIRE_OPENID && !$is_logged}
	<div class="menu">
		<a href="{$config.APP_URL}auth/" class="button">Login with <strong>{$config.OPENID_NAME}</strong></a>
	</div>
	{elseif $is_logged}
	<div class="menu">
		<h2><span class="label success">Logged in</span></h2>
	</div>
	{/if}
</nav>

<main>