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

class HTTP
{
	const FORM = 'application/x-www-form-urlencoded';
	const JSON = 'application/json; charset=UTF-8';
	const XML = 'text/xml';

	const CLIENT_DEFAULT = 'default';
	const CLIENT_CURL = 'curl';

	public $client = null;

	/**
	 * A list of common User-Agent strings, one of them is used
	 * randomly every time an object has a new instance.
	 * @var array
	 */
	public $uas = [
		'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/48.0.2564.116 Chrome/48.0.2564.116 Safari/537.36',
		'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:45.0) Gecko/20100101 Firefox/45.0',
		'Mozilla/5.0 (X11; Linux x86_64; rv:38.9) Gecko/20100101 Goanna/2.0 Firefox/38.9 PaleMoon/26.1.1',
	];

	/**
	 * User agent
	 * @var string
	 */
	public $user_agent = null;

	/**
	 * Default HTTP headers sent with every request
	 * @var array
	 */
	public $headers = [
	];

	/**
	 * Options for the SSL stream wrapper
	 * Be warned that by default we allow self signed certificates
	 * See http://php.net/manual/en/context.ssl.php
	 * @var array
	 */
	public $ssl_options = [
		'verify_peer'		=>	true,
		'verify_peer_name'	=>	true,
		'allow_self_signed'	=>	true,
		'SNI_enabled'		=>	true,
	];

	/**
	 * Options for the HTTP stream wrapper
	 * See http://php.net/manual/en/context.http.php
	 * @var array
	 */
	public $http_options = [
		'max_redirects'		=>	10,
		'timeout'			=>	10,
		'ignore_errors'		=>	true,
	];

	/**
	 * List of cookies sent to the server, will contain the cookies
	 * set by the server after a request.
	 * @var array
	 */
	public $cookies = [];

	/**
	 * Prepend this string to every request URL
	 * (helpful for API calls)
	 * @var string
	 */
	public $url_prefix = '';

	/**
	 * Class construct
	 */
	public function __construct()
	{
		// Use faster client by default
		$this->client = function_exists('curl_exec') ? self::CLIENT_CURL : self::CLIENT_DEFAULT;

		// Random user agent
		$this->user_agent = $this->uas[array_rand($this->uas)];
	}

	/**
	 * Enable or disable SSL security,
	 * this includes disabling or enabling self signed certificates
	 * which are allowed by default
	 * @param boolean $enable TRUE to enable certificate check, FALSE to disable
	 */
	public function setSecure($enable = true)
	{
		$this->ssl_options['verify_peer'] = $enable;
		$this->ssl_options['verify_peer_name'] = $enable;
		$this->ssl_options['allow_self_signed'] = !$enable;
	}

	/**
	 * Make a GET request
	 * @param  string $url                URL to request
	 * @param  array  $additional_headers Optional headers to send with request
	 * @return object                     a stdClass object containing 'headers' and 'body'
	 */
	public function GET($url, Array $additional_headers = null)
	{
		return $this->request('GET', $url, null, $additional_headers);
	}

	/**
	 * Make a GET request
	 * @param  string $url                URL to request
	 * @param  array  $data 			  Data to send with POST request
	 * @param  string $type 			  Type of data: 'form' for HTML form or 'json' to encode array in JSON
	 * @param  array  $additional_headers Optional headers to send with request
	 * @return HTTP_Response
	 */
	public function POST($url, $data = [], $type = self::FORM, Array $additional_headers = [])
	{
		if ($type == self::FORM)
		{
			$data = http_build_query($data, null, '&');
		}
		elseif ($type == self::JSON)
		{
			$data = json_encode($data);
		}
		elseif ($type == self::XML)
		{
			if ($data instanceof \SimpleXMLElement)
			{
				$data = $data->asXML();
			}
			elseif ($data instanceof \DOMDocument)
			{
				$data = $data->saveXML();
			}
			elseif (!is_string($data))
			{
				throw new \InvalidArgumentException('Data is not a valid XML object or string.');
			}
		}

		$additional_headers['Content-Length'] = strlen($data);
		$additional_headers['Content-Type'] = $type;

		return $this->request('POST', $url, $data, $additional_headers);
	}

	/**
	 * Make a custom request
	 * @param  string $method             HTTP verb (GET, POST, PUT, etc.)
	 * @param  string $url                URL to request
	 * @param  string $content            Data to send with request
	 * @param  [type] $additional_headers [description]
	 * @return HTTP_Response
	 */
	public function request($method, $url, $data = null, Array $additional_headers = null)
	{
		static $redirect_codes = [301, 302, 303, 307, 308];

		$url = $this->url_prefix . $url;

		$headers = $this->headers;

		if (!is_null($additional_headers))
		{
			$headers = array_merge($headers, $additional_headers);
		}

		if ($this->user_agent && !isset($headers['User-Agent']))
		{
			$headers['User-Agent'] = $this->user_agent;
		}

		// Manual management of redirects
		if (isset($this->http_options['max_redirects']))
		{
			$max_redirects = (int) $this->http_options['max_redirects'];
		}
		else
		{
			$max_redirects = 10;
		}

		$previous = null;

		// Follow redirect until we reach maximum
		for ($i = 0; $i <= $max_redirects; $i++)
		{
			// Make request
			$client = $this->client . 'ClientRequest';
			$response = $this->$client($method, $url, $data, $headers);
			$response->previous = $previous;

			// Apply cookies to current client for next request
			$this->cookies = array_merge($this->cookies, $response->cookies);

			// Request failed, or not a redirect, stop here
			if (!$response->status || !in_array($response->status, $redirect_codes) || empty($response->headers['location']))
			{
				break;
			}

			// Change method to GET
			if ($response->status == 303)
			{
				$method = 'GET';
			}

			// Get new URL
			$location = $response->headers['location'];

			if (is_array($location))
			{
				$location = end($location);
			}

			if (!parse_url($location))
			{
				throw new \RuntimeException('Invalid HTTP redirect: Location is not a valid URL.');
			}

			$url = self::mergeURLs($url, $location, true);
			$previous = $response;
		}

		return $response;
	}

	/**
	 * Transforms a parse_url array back into a string
	 * @param  Array  $url
	 * @return string
	 */
	static public function glueURL(Array $url)
	{
		static $parts = [
			'scheme'   => '%s:',
			'host'     => '//%s',
			'port'     => ':%d',
			'user'     => '%s',
			'pass'     => ':%s',
			'path'     => '%s',
			'query'    => '?%s',
			'fragment' => '#%s',
		];

		$out = [];

		foreach ($parts as $name => $str)
		{
			if (isset($url[$name]))
			{
				$out[] = sprintf($str, $url[$name]);
			}

			if ($name == 'pass' && isset($url['user']) || isset($url['pass']))
			{
				$out[] = '@';
			}
		}

		return implode('', $out);
	}

	/**
	 * Merge two URLs, managing relative $b URL
	 * @param  string $a Primary URL
	 * @param  string $b New URL
	 * @param  boolean $dismiss_query Set to TRUE to dismiss query part of the primary URL
	 * @return string
	 */
	static public function mergeURLs($a, $b, $dismiss_query = false)
	{
		$a = parse_url($a);
		$b = parse_url($b);

		if ($dismiss_query)
		{
			// Don't propagate query params between redirects
			unset($a['query']);
		}

		// Relative URL
		if (!isset($b['host']) && isset($b['path']) && substr(trim($b['path']), 0, 1) != '/')
		{
			$path = preg_replace('![^/]*$!', '', $a['path']);
			$path.= preg_replace('!^\./!', '', $b['path']);
			unset($a['path']);

			// replace // or  '/./' or '/foo/../' with '/'
			$b['path'] = preg_replace('#/(?!\.\.)[^/]+/\.\./|/\.?/#', '/', $path);
		}

		$url = array_merge($a, $b);
		return self::glueURL($url);
	}

	/**
	 * RFC 6570 URI template replacement, supports level 1 and level 2
	 * @param string $uri    URI with placeholders
	 * @param Array  $params Parameters (placeholders)
	 * @link  https://www.rfc-editor.org/rfc/rfc6570.txt
	 * @return string
	 */
	static public function URITemplate($uri, Array $params = [])
	{
		static $var_name = '(?:[0-9a-zA-Z_]|%[0-9A-F]{2})+';

		// Delimiters
		static $delims = [
			'%3A' => ':', '%2F' => '/', '%3F' => '?', '%23' => '#',
			'%5B' => '[', '%5D' => ']', '%40' => '@', '%21' => '!',
			'%24' => '$', '%26' => '&', '%27' => '\'', '%28' => '(',
			'%29' => ')', '%2A' => '*', '%2B' => '+', '%2C' => ',',
			'%3B' => ';', '%3D' => '=',
		];

		// Level 2: {#variable} => #/foo/bar
		$uri = preg_replace_callback('/\{#(' . $var_name . ')\}/i', function ($match) use ($params, $delims) {
			if (!isset($params[$match[1]]))
			{
				return '';
			}

			return '#' . strtr(rawurlencode($params[$match[1]]), $delims);
		}, $uri);

		// Level 2: {+variable} => /foo/bar
		$uri = preg_replace_callback('/\{\+(' . $var_name . ')\}/i', function ($match) use ($params, $delims) {
			if (!isset($params[$match[1]]))
			{
				return '';
			}

			return strtr(rawurlencode($params[$match[1]]), $delims);
		}, $uri);

		// Level 1: {variable} => %2Ffoo%2Fbar
		$uri = preg_replace_callback('/\{(' . $var_name . ')\}/i', function ($match) use ($params) {
			if (!isset($params[$match[1]]))
			{
				return '';
			}

			return rawurlencode($params[$match[1]]);
		}, $uri);

		return $uri;
	}

	/**
	 * HTTP request using PHP stream and file_get_contents
	 * @param  string $method
	 * @param  string $url
	 * @param  string $data
	 * @param  array $headers
	 * @return HTTP_Response
	 */
	protected function defaultClientRequest($method, $url, $data, Array $headers)
	{
		$request = '';

		//Add cookies
		if (count($this->cookies) > 0)
		{
			$headers['Cookie'] = '';

			foreach ($this->cookies as $key=>$value)
			{
				if (!empty($headers['Cookie'])) $headers['Cookie'] .= '; ';
				$headers['Cookie'] .= $key . '=' . $value;
			}
		}

		foreach ($headers as $key=>$value)
		{
			$request .= $key . ': ' . $value . "\r\n";
		}

		$http_options = [
			'method'          => $method,
			'header'          => $request,
			'content'         => $data,
			'max_redirects'   => 0,
			'follow_location' => false,
		];

		$http_options = array_merge($this->http_options, $http_options);

		$context = stream_context_create([
			'http'  =>  $http_options,
			'ssl'	=>	$this->ssl_options,
		]);

		$request = $method . ' ' . $url . "\r\n" . $request . "\r\n" . $data;

		$r = new HTTP_Response;
		$r->url = $url;
		$r->request = $request;

		try {
			$r->body = file_get_contents($url, false, $context);
		}
		catch (\Exception $e)
		{
			if (!empty($this->http_options['ignore_errors']))
			{
				$r->error = $e->getMessage();
				return $r;
			}

			throw $e;
		}

		if ($r->body === false && empty($http_response_header))
			return $r;

		$r->fail = false;
		$r->size = strlen($r->body);

		foreach ($http_response_header as $line)
		{
			$header = strtok($line, ':');
			$value = strtok('');

			if ($value === false)
			{
				if (preg_match('!^HTTP/1\.[01] ([0-9]{3}) !', $line, $match))
				{
					$r->status = (int) $match[1];
				}
				else
				{
					$r->headers[] = $line;
				}
			}
			else
			{
				$header = trim($header);
				$value = trim($value);

				// Add to cookies array
				if (strtolower($header) == 'set-cookie')
				{
					$cookie_key = strtok($value, '=');
					$cookie_value = strtok(';');
					$r->cookies[$cookie_key] = $cookie_value;
				}

				$r->headers[$header] = $value;
			}
		}

		return $r;
	}

	/**
	 * HTTP request using CURL
	 * @param  string $method
	 * @param  string $url
	 * @param  string $data
	 * @param  array $headers
	 * @return HTTP_Response
	 */
	protected function curlClientRequest($method, $url, $data, Array $headers)
	{
		// Sets headers in the right format
		foreach ($headers as $key=>&$header)
		{
			$header = $key . ': ' . $header;
		}

		$r = new HTTP_Response;

		$c = curl_init();

		curl_setopt_array($c, [
			CURLOPT_URL            => $url,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS      => 1,
			CURLOPT_SSL_VERIFYPEER => !empty($this->ssl_options['verify_peer']),
			CURLOPT_SSL_VERIFYHOST => !empty($this->ssl_options['verify_peer_name']) ? 2 : 0,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => !empty($this->http_options['timeout']) ? (int) $this->http_options['timeout'] : 30,
			CURLOPT_POST           => $method == 'POST' ? true : false,
			CURLOPT_SAFE_UPLOAD    => true, // Disable file upload with values beginning with @
			CURLINFO_HEADER_OUT    => true,
		]);

		if ($data !== null)
		{
			curl_setopt($c, CURLOPT_POSTFIELDS, $data);
		}

		if (!empty($this->ssl_options['cafile']))
		{
			curl_setopt($c, CURLOPT_CAINFO, $this->ssl_options['cafile']);
		}

		if (!empty($this->ssl_options['capath']))
		{
			curl_setopt($c, CURLOPT_CAPATH, $this->ssl_options['capath']);
		}

		if (count($this->cookies) > 0)
		{
			// Concatenates cookies
			$cookies = [];

			foreach ($this->cookies as $key=>$value)
			{
				$cookies[] = $key . '=' . $value;
			}

			$cookies = implode('; ', $cookies);

			curl_setopt($c, CURLOPT_COOKIE, $cookies);
		}

		curl_setopt($c, CURLOPT_HEADERFUNCTION, function ($c, $header) use (&$r) {
			$name = trim(strtok($header, ':'));
			$value = strtok('');

			// End of headers, stop here
			if ($name === '')
			{
				return strlen($header);
			}
			elseif ($value === false)
			{
				$r->headers[] = $name;
			}
			else
			{
				$value = trim($value);

				if (strtolower($name) == 'set-cookie')
				{
					$cookie_key = strtok($value, '=');
					$cookie_value = strtok(';');
					$r->cookies[$cookie_key] = $cookie_value;
				}

				$r->headers[$name] = $value;
			}

			return strlen($header);
		});

		$r->url = $url;

		$r->body = curl_exec($c);
		$r->request = curl_getinfo($c, CURLINFO_HEADER_OUT) . $data;

		if ($error = curl_error($c))
		{
			if (!empty($this->http_options['ignore_errors']))
			{
				$r->error = $error;
				return $r;
			}

			throw new \RuntimeException('cURL error: ' . $error);
		}

		if ($r->body === false)
		{
			return $r;
		}

		$r->fail = false;
		$r->size = strlen($r->body);
		$r->status = curl_getinfo($c, CURLINFO_HTTP_CODE);

		curl_close($c);

		return $r;
	}
}

class HTTP_Response
{
	public $url = null;
	public $headers = [];
	public $body = null;
	public $fail = true;
	public $cookies = [];
	public $status = null;
	public $request = null;
	public $size = 0;
	public $error = null;
	public $previous = null;

	public function __construct()
	{
		$this->headers = new HTTP_Headers;
	}

	public function __toString()
	{
		return $this->body;
	}
}

class HTTP_Headers implements \ArrayAccess
{
	protected $headers = [];

	public function __get($key)
	{
		$key = strtolower($key);

		if (array_key_exists($key, $this->headers))
		{
			return $this->headers[$key][1];
		}

		return null;
	}

	public function __set($key, $value)
	{
		if (is_null($key))
		{
			$this->headers[] = [null, $value];
		}
		else
		{
			$key = trim($key);
			$this->headers[strtolower($key)] = [$key, $value];
		}
	}

	public function offsetGet($key)
	{
		return $this->__get($key);
	}

	public function offsetExists($key)
	{
		$key = strtolower($key);

		return array_key_exists($key, $this->headers);
	}

	public function offsetSet($key, $value)
	{
		return $this->__set($key, $value);
	}

	public function offsetUnset($key)
	{
		unset($this->headers[strtolower($key)]);
	}

	public function toArray()
	{
		return explode("\r\n", (string)$this);
	}

	public function __toString()
	{
		$out = '';

		foreach ($this->headers as $header)
		{
			$out .= (!is_null($header[0]) ? $header[0] . ': ' : '') . $header[1] . "\r\n";
		}

		return $out;
	}
}