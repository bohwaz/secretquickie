<?php

use SecretQuickie\SecretQuickie;

require __DIR__ . '/../app/bootstrap.php';

assert($sq = new SecretQuickie);

// Store a secret with a random password
assert(
	strlen($uri = $sq->store('test')),
	'Storing secret with random password'
);

assert(
	strlen($key = strtok($uri, '&')),
	'Splitting key from password'
);

assert(
	apcu_exists(\SecretQuickie\APCU_PREFIX . $key),
	'Checking key is stored in APCu'
);

assert(
	strlen($password = strtok('&')),
	'Split password from key'
);

assert(
	$sq->retrieve($key, $password) === 'test',
	'Retrieving clear text secret'
);

assert(
	!apcu_exists(\SecretQuickie\APCU_PREFIX . $key),
	'Checking secret has been removed from memory'
);

// Store a secret with a user password
$password = 'abcd1234';

assert(
	strlen($key = $sq->store('test', \SecretQuickie\DEFAULT_EXPIRY, $password)),
	'Storing secret with user password'
);

assert(
	apcu_exists(\SecretQuickie\APCU_PREFIX . $key),
	'Checking key is stored in APCu'
);

assert(
	$sq->retrieve($key, $password) === 'test',
	'Retrieving clear text secret'
);

assert(
	!apcu_exists(\SecretQuickie\APCU_PREFIX . $key),
	'Checking secret has been removed from memory'
);