<?php
/*
    This file is part of KD2FW -- <http://dev.kd2.org/>

    Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
    All rights reserved.

    KD2FW is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Foobar is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2;

use KD2\HTTP;

/**
 * OpenIDConnect: an OpenID Connect (OAuth 2.0) simple client library
 *
 * @author  bohwaz  http://bohwaz.net/
 * @license BSD
 */
class OpenIDConnect
{
	/**
	 * Auto-discovery configuration
	 * @var stdClass
	 */
	protected $config;

	/**
	 * Client ID
	 * @var string
	 */
	protected $id;

	/**
	 * Client secret
	 * @var string
	 */
	protected $secret;

	/**
	 * Client redirect URL
	 * @var string
	 */
	protected $redirect_url;

	/**
	 * User access token (once auth is successful)
	 * @var string
	 */
	protected $token;

	/**
	 * Instantiates a new OpenIDConnect object
	 * @param string $url          Server URL, eg. https://accounts.google.com/
	 * Auto-discovery will be used to find server configuration
	 * @param string $id           Client ID, obtained from provider
	 * @param string $secret       Client secret, obtained from provider
	 * @param string $redirect_url Client redirect URL
	 */
	public function __construct($url, $id, $secret, $redirect_url)
	{
		$url = rtrim($url, '/');
		$response = (new HTTP)->GET($url . '/.well-known/openid-configuration');

		if (!$response)
		{
			throw new \RuntimeException(sprintf('Not an OpenIDConnect server, cannot find configuration: %s', $url));
		}

		$this->config = json_decode($response);

		if (!$this->config)
		{
			throw new \RuntimeException('Invalid configuration');
		}

		$this->id = $id;
		$this->secret = $secret;
		$this->redirect_url = $redirect_url;
	}

	/**
	 * Authenticate client with provider, may redirect to a third party URL
	 * @return void|boolean
	 */
	public function authenticate()
	{
		if (isset($_GET['code']) || isset($_GET['error']))
		{
			return $this->handleOAuthResponse();
		}

		header('Location: ' . $this->getAuthenticationURL());
		exit;
	}

	/**
	 * Returns Authentication URL where user should be redirected to perform auth
	 * @return string
	 */
	public function getAuthenticationURL()
	{
		$params = [
			'response_type' => 'code',
			'client_id'     => $this->id,
			'redirect_uri'  => $this->redirect_url,
			'state'         => 'random' . time(), // FIXME: unsafe
			'scope'         => implode(' ', $this->config->scopes_supported),
		];

		$params = http_build_query($params);

		$url = $this->config->authorization_endpoint . '?' . $params;

		return $url;
	}

	/**
	 * Handles response from OAuth server, if possible
	 * @return boolean TRUE if user is correctly authenticated
	 */
	public function handleOAuthResponse()
	{
		$this->checkError($_GET);

		if (empty($_GET['code']))
		{
			throw new \RuntimeException('OAuth 2.0: Missing request code.');
		}

		$code = $_GET['code'];

		$params = [
			'code'          => $_GET['code'],
			'client_id'     => $this->id,
			'client_secret' => $this->secret,
			'redirect_uri'  => $this->redirect_url,
			'grant_type'    => 'authorization_code',
		];

		$response = (new HTTP)->POST($this->config->token_endpoint, $params);

		if (!$response)
		{
			return false;
		}

		$response = json_decode($response, true);

		$this->checkError($response);

		if (empty($response['access_token']))
		{
			return false;
		}

		$this->token = $response['access_token'];

		return true;
	}

	/**
	 * Checks server response for errors and throws a RuntimeException if one is found
	 * @param  Array $response  Server response
	 * @return boolean
	 */
	protected function checkError(Array $response)
	{
		if (isset($response['error']))
		{
			$error = $response['error'];
			$error .= isset($response['error_description']) ? ' -- ' . $response['error_description'] : '';
			throw new \RuntimeException('OAuth authentication error: ' . $error);
		}

		return false;
	}

	/**
	 * Returns Authorization header with access token to perform requests to provider
	 * @return string
	 */
	public function getAuthorizationHeader()
	{
		if (!$this->token)
		{
			return false;
		}

		return 'Bearer ' . $this->token;
	}

	/**
	 * Returns user info from provider
	 * @return stdClass
	 */
	public function getUserInfo()
	{
		$response = (new HTTP)->GET($this->config->userinfo_endpoint, [
			'Authorization' => $this->getAuthorizationHeader()
		]);

		if (!$response)
		{
			return false;
		}

		$response = json_decode($response);

		$this->checkError((array) $response);

		return $response;
	}
}