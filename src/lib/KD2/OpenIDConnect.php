<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/
  
  Copyright (c) 2001-2016 BohwaZ <http://bohwaz.net/>
  All rights reserved.
  
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.
  2. Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
  
  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
  THE POSSIBILITY OF SUCH DAMAGE.
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