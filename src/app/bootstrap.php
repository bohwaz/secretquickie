<?php

namespace SecretQuickie;

use KD2\Smartyer;
use KD2\ErrorManager;
use KD2\Security;
use KD2\CacheCookie;

/**
 * Loads the list of definitions of javascript hashes for Subresource Integrity
 * @param  string $file File containing list of SHA384 hashes
 * @return array
 */
function load_js_hashes($file)
{
	$hashes = [];

	foreach (file($file) as $line)
	{
		$line = trim($line);

		$line = preg_split('/\s+/', $line);

		if (count($line) != 2)
		{
			continue;
		}

		$hashes[$line[1]] = $line[0];
	}

	return $hashes;
}

/**
 * Very basic dotenv file parser
 * @param  string $file
 * @param  array  $required Required elements
 * @return void
 */
function load_dotenv($file, $required = [])
{
	$content = file_get_contents($file);
	$content = preg_replace('/#.*$/m', '', $content);
	$vars = parse_ini_string($content);

	foreach ($required as $key)
	{
		if (!array_key_exists($key, $vars))
		{
			throw new \RuntimeException(sprintf('%s file is missing required variable %s', $file, $key));
		}
	}

	foreach ($vars as $key=>&$value)
	{
		$key = strtoupper($key);

		if ($key == 'APP_URL')
		{
			// Normalize app url
			$value = rtrim($value, '/') . '/';
		}

		define(__NAMESPACE__ . '\\' . $key, $value);
	}

	return $vars;
}

$required = [
	'APP_NAME',
	'APP_LOGO',
	'APP_URL',
	'APP_ENV',
	'APP_SECRET',
	'APCU_PREFIX',
	'REQUIRE_OPENID',
	'USE_COMPOSER',
	'OPENID_NAME',
	'OPENID_URL',
	'OPENID_CLIENT_ID',
	'OPENID_CLIENT_SECRET',
	'OPENID_EMAIL_WHITELIST',
];

$dotenv = load_dotenv(__DIR__ . '/../.env', $required);

// Autoload: use system-wide libraries if not found in local lib directory

if (USE_COMPOSER)
{
	require __DIR__ . '/../vendor/autoload.php';
}
else
{
	set_include_path(__DIR__ . '/../lib' . PATH_SEPARATOR . get_include_path());

	spl_autoload_register(function ($name) {
	       // Can't use default spl_autoload as it is lowercasing file names :(
	       $file =  str_replace('\\', '/', $name) . '.php';
	       require $file;
	});
}

// Init error manager

ErrorManager::enable(APP_ENV != 'dev' ? ErrorManager::PRODUCTION : ErrorManager::DEVELOPMENT);
ErrorManager::setLogFile(__DIR__ . '/error.log');

// Check config

if (OPENID_EMAIL_WHITELIST)
{
	try {
		preg_match('/' . OPENID_EMAIL_WHITELIST . '/', '');
	}
	catch (\Exception $e)
	{
		throw new \LogicException('Invalid regexp in OPENID_EMAIL_WHITELIST: ' . $e->getMessage());
	}
}

// Init template system

Smartyer::setTemplateDir(__DIR__ . '/templates');
Smartyer::setCompileDir(sys_get_temp_dir());

$tpl = new Smartyer;

$tpl->assign('config', $dotenv);
$tpl->assign('js_hashes', load_js_hashes(__DIR__ . '/../js_hashes.txt'));
$tpl->assign('asset_version', '2017.0.1');

// Set secret

Security::tokenSetSecret(APP_SECRET);

// User session

$user = new User;

$tpl->assign('is_logged', $user->isLogged());
