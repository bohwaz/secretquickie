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

/**
 * Form management helper
 * - CSRF protection
 * - validate form fields
 * - return form fields
 */
class Form
{
	/**
	 * Custom validation rules
	 * @var array
	 */
	static protected $custom_validation_rules = [];

	/**
	 * Secret used for tokens
	 * @var string
	 */
	static protected $token_secret;

	/**
	 * Sets the secret key used to hash and check the CSRF tokens
	 * @param  string $secret Whatever secret you may like, must be the same for all the user session
	 * @return boolean true
	 */
	static public function tokenSetSecret($secret)
	{
		self::$token_secret = $secret;
		return true;
	}

	/**
	 * Generate a single use token and return the value
	 * The token will be HMAC signed and you can use it directly in a HTML form
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @param  integer $expire Number of hours before the hash will expire
	 * @return string         HMAC signed token
	 */
	static public function tokenGenerate($action = null, $expire = 5)
	{
		if (is_null(self::$token_secret))
		{
			throw new \RuntimeException('No CSRF token secret has been set.');
		}

		$action = self::tokenAction($action);

		$random = random_int(0, PHP_INT_MAX);
		$expire = floor(time() / 3600) + $expire;
		$value = $expire . $random . $action;

		$hash = hash_hmac('sha256', $expire . $random . $action, self::$token_secret);

		return $hash . '/' . dechex($expire) . '/' . dechex($random);
	}

	/**
	 * Checks a CSRF token
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @param  string $value  User supplied value, if NULL then $_POST[automatic name] will be used
	 * @return boolean
	 */
	static public function tokenCheck($action = null, $value = null)
	{
		$action = self::tokenAction($action);

		if (is_null($value))
		{
			$name = self::tokenFieldName($action);

			if (empty($_POST[$name]))
			{
				return false;
			}

			$value = $_POST[$name];
		}

		$value = explode('/', $value, 3);

		if (count($value) != 3)
		{
			return false;
		}

		$user_hash = $value[0];
		$expire = hexdec($value[1]);
		$random = hexdec($value[2]);

		// Expired token
		if ($expire < ceil(time() / 3600))
		{
			return false;
		}

		$hash = hash_hmac('sha256', $expire . $random . $action, self::$token_secret);

		return hash_equals($hash, $user_hash);
	}

	/**
	 * Generates a random field name for the current token action
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @return string
	 */
	static public function tokenFieldName($action = null)
	{
		$action = self::tokenAction($action);
		return 'ct_' . sha1($action . $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SERVER_NAME'] . $action);
	}

	/**
	 * Returns the supplied action name or if it is NULL, then the REQUEST_URI
	 * @param  string $action
	 * @return string
	 */
	static protected function tokenAction($action = null)
	{
		// Default action, will work as long as the check is on the same URI as the generation
		if (is_null($action) && !empty($_SERVER['REQUEST_URI']))
		{
			$url = parse_url($_SERVER['REQUEST_URI']);

			if (!empty($url['path']))
			{
				$action = $url['path'];
			}
		}

		return $action;
	}

	/**
	 * Returns HTML code to embed a CSRF token in a form
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @return string HTML <input type="hidden" /> element
	 */
	static public function tokenHTML($action = null)
	{
		return '<input type="hidden" name="' . self::tokenFieldName($action) . '" value="' . self::tokenGenerate($action) . '" />';
	}

	/**
	 * Returns TRUE if the form has this key and it's not NULL
	 * @param  string  $key Key to find in the form
	 * @return boolean
	 */
	static public function has($key)
	{
		return isset($_POST[$key]);
	}

	/**
	 * Parses rules for form validation
	 * @param  string $str Rule description
	 * @return array List of rules with parameters
	 */
	static protected function parseRules($str)
	{
		$str = preg_split('/(?<!\\\\)\|/', $str);
		$rules = [];

		foreach ($str as $rule)
		{
			$name = strtok($rule, ':');
			$rules[$name] = [];
			
			while (($param = strtok(',')) !== false)
			{
				$rules[$name][] = $param;
			}
		}

		return $rules;
	}

	/**
	 * Returns the value for a form field, or NULL
	 * 
	 * @param  string $key Field name
	 * @return mixed
	 */
	static public function get($field)
	{
		if (is_array($field))
		{
			$out = new \stdClass;

			foreach ($field as $key => $value)
			{
				$name = is_int($key) ? $value : $key;
				$out->$name = self::get($name);

				if (!is_int($key))
				{
					$rules = self::parseRules($value);

					foreach ($rules as $rule => $params)
					{
						$out->$name = self::filterField($out->$name, $rule, $params);
					}
				}
			}

			return $out;
		}

		return isset($_POST[$field]) ? $_POST[$field] : null;
	}

	static public function filterField($value, $filter, array $params = [])
	{
		switch ($filter)
		{
			case 'date':
				return new \DateTime($value);
			case 'date_format':
				return \DateTime::createFromFormat($params[0], $value);
			case 'int':
			case 'integer':
				return (int) $value;
			case 'bool':
			case 'boolean':
				return (bool) $value;
			case 'string':
				return trim($value);
		}

		return $value;
	}

	/**
	 * Register a custom validation rule
	 * 
	 * @param  string   $name     Rule name
	 * @param  Callable $callback Callback (must return a boolean)
	 * @return void
	 */
	static public function registerValidationRule($name, Callable $callback)
	{
		self::$custom_validation_rules[$name] = $callback;
	}

	/**
	 * Check a form field against a rule
	 * 
	 * @param  string $key       Field name
	 * @param  string $rule_name Rule name
	 * @param  Array  $params    Parameters of the rule
	 * @param  Array  $source    Source of the field data
	 * @param  Array  $rules     Complete list of rules
	 * @return boolean
	 */
	static public function validateRule($key, $rule_name, Array $params = [], Array $source = null, Array $rules = [])
	{
		$value = isset($source[$key]) ? $source[$key] : null;

		switch ($rule_name)
		{
			case 'required':
				if (isset($rules['file']))
				{
					// Checked in 'file' rule
					return true;
				}
				elseif (is_array($value) || $value instanceof \Countable)
				{
					return count($value) > 0;
				}
				elseif (is_string($value))
				{
					return trim($value) !== '';
				}
				return !is_null($value);
			case 'required_with':
				$required = false;

				foreach ($params as $condition)
				{
					if (isset($source[$condition]))
					{
						$required = true;
						break;
					}
				}

				return $required ? self::validateRule($key, 'required', $params, $source) : true;
			case 'required_with_all':
				$required = 0;

				foreach ($params as $condition)
				{
					if (isset($source[$condition]))
					{
						$required++;
					}
				}

				return $required == count($params) ? self::validateRule($key, 'required', $params, $source) : true;
			case 'required_without':
				$required = false;

				foreach ($params as $condition)
				{
					if (!isset($source[$condition]))
					{
						$required = true;
						break;
					}
				}

				return $required ? self::validateRule($key, 'required', $params, $source) : true;
			case 'required_without_all':
				$required = 0;

				foreach ($params as $condition)
				{
					if (!isset($source[$condition]))
					{
						$required++;
					}
				}

				return $required == count($params) ? self::validateRule($key, 'required', $params, $source) : true;
			case 'required_if':
				$required = false;
				$if_value = isset($source[$params[0]]) ? $source[$params[0]] : null;

				for ($i = 1; $i < count($params); $i++)
				{
					if ($params[$i] == $if_value)
					{
						$required = true;
						break;
					}
				}

				return $required ? self::validateRule($key, 'required', $params, $source) : true;
			case 'required_unless':
				$required = true;
				$if_value = isset($source[$params[0]]) ? $source[$params[0]] : null;

				for ($i = 1; $i < count($params); $i++)
				{
					if ($params[$i] == $if_value)
					{
						$required = false;
						break;
					}
				}

				return $required ? self::validateRule($key, 'required', $params, $source) : true;
			case 'absent':
				return $value === null;
		}

		// Ignore rules for empty fields, except 'required*'
		if ($rule_name != 'file' && ($value === null || (is_string($value) && trim($value) === '')))
		{
			return true;
		}

		switch ($rule_name)
		{
			case 'file':
				if (!isset($_FILES[$key]) && isset($rules['required']))
				{
					return false;
				}
				elseif (!isset($_FILES[$key]))
				{
					return true;
				}

				return ($value = $_FILES[$key]) && !empty($value['size']) && !empty($value['tmp_name']) && empty($value['error']);
			case 'active_url':
				$url = parse_url($value);
				return isset($url['host']) && strlen($url['host']) && (checkdnsrr($url['host'], 'A') || checkdnsrr($url['host'], 'AAAA'));
			case 'alpha':
				return preg_match('/^[\pL\pM]+$/u', $value);
			case 'alpha_dash':
				return preg_match('/^[\pL\pM\pN_-]+$/u', $value);
			case 'alpha_num':
				return preg_match('/^[\pL\pM\pN]+$/u', $value);
			case 'array':
				return is_array($value);
			case 'between':
				return isset($params[0]) && isset($params[1]) && $value >= $params[0] && $value <= $params[1];
			case 'boolean':
			case 'bool':
				return ($value == 0 || $value == 1);
			case 'color':
				return preg_match('/^#?[a-f0-9]{6}$/', $value);
			case 'confirmed':
				$key_c = $key . '_confirmed';
				return isset($source[$key_c]) && $value == $source[$key_c];
			case 'date':
				return is_object($value) ? $value instanceof \DateTimeInterface : (bool) strtotime($value);
			case 'date_format':
				$date = date_parse_from_format($params[0], $value);
				return $date['warning_count'] === 0 && $date['error_count'] === 0;
			case 'different':
				return isset($params[0]) && isset($source[$params[0]]) && $value != $source[$params[0]];
			case 'digits':
				return is_numeric($value) && strlen((string) $value) == $params[0];
			case 'digits_between':
				$len = strlen((string) $value);
				return is_numeric($value) && $len >= $params[0] && $len <= $params[0];
			case 'email':
				// Compatibility with IDN domains
				if (function_exists('idn_to_ascii'))
				{
					$host = substr($value, strpos($value, '@') + 1);
					$host = @idn_to_ascii($host); // Silence errors because of PHP 7.2 http://php.net/manual/en/function.idn-to-ascii.php
					$value = substr($value, 0, strpos($value, '@')+1) . $host;
				}

				return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
			case 'gt':
				return isset($params[0]) && isset($source[$params[0]]) && $value > $source[$params[0]];
			case 'gte':
				return isset($params[0]) && isset($source[$params[0]]) && $value >= $source[$params[0]];
			case 'in':
				return in_array($value, $params);
			case 'in_array':
				$field = isset($params[0]) && isset($source[$params[0]]) ? $source[$params[0]] : null;
				return $field && is_array($field) && in_array($value, $field);
			case 'integer':
			case 'int':
				return is_int($value);
			case 'ip':
				return filter_var($value, FILTER_VALIDATE_IP) !== false;
			case 'json':
				return json_decode($value) !== null;
			case 'lt':
				return isset($params[0]) && isset($source[$params[0]]) && $value < $source[$params[0]];
			case 'lte':
				return isset($params[0]) && isset($source[$params[0]]) && $value <= $source[$params[0]];
			case 'max':
				$size = is_array($value) ? count($value) : (is_numeric($value) ? $value : strlen($value));
				return isset($params[0]) && $size <= $params[0];
			case 'min':
				$size = is_array($value) ? count($value) : (is_numeric($value) ? $value : strlen($value));
				return isset($params[0]) && $size >= $params[0];
			case 'not_in':
				return !in_array($value, $params);
			case 'numeric':
				return is_numeric($value);
			case 'present':
				return isset($source[$key]);
			case 'regex':
				return isset($params[0]) && preg_match($params[0], $value);
			case 'same':
				return isset($params[0]) && isset($source[$params[0]]) && $source[$params[0]] == $value;
			case 'size':
				$size = is_array($value) ? count($value) : (is_numeric($value) ? $value : strlen($value));
				return isset($params[0]) && $size == (int) $params[0];
			case 'string':
				return is_string($value);
			case 'timezone':
				try {
					new \DateTimeZone($value);
					return true;
				}
				catch (\Exception $e) {
					return false;
				}
			case 'url':
				return filter_var($value, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED) !== false;
			// Dates
			case 'after':
				return isset($params[0]) && ($date1 = strtotime($value)) && ($date2 = strtotime($params[0])) && $date1 > $date2;
			case 'after_or_equal':
				return isset($params[0]) && ($date1 = strtotime($value)) && ($date2 = strtotime($params[0])) && $date1 >= $date2;
			case 'before':
				return isset($params[0]) && ($date1 = strtotime($value)) && ($date2 = strtotime($params[0])) && $date1 < $date2;
			case 'before_or_equal':
				return isset($params[0]) && ($date1 = strtotime($value)) && ($date2 = strtotime($params[0])) && $date1 <= $date2;
			default:
				if (isset(self::$custom_validation_rules[$rule_name]))
				{
					return call_user_func_array(self::$custom_validation_rules[$rule_name], [$key, $params, $value, $source]);
				}

				throw new \UnexpectedValueException('Invalid rule name: ' . $rule_name);
		}
	}

	/**
	 * Validate but add CSRF token check to that
	 * 
	 * @param  string $token_action CSRF token action name
	 * @param  Array  $all_rules    List of rules, eg. 'login' => 'required|string'
	 * @param  Array  &$errors      List of errors encountered
	 * @return boolean
	 */
	static public function check($token_action, Array $all_rules, Array &$errors = [])
	{
		if (!self::tokenCheck($token_action))
		{
			$errors[] = ['rule' => 'csrf'];
			return false;
		}

		return self::validate($all_rules, $errors);
	}

	/**
	 * Validate the current form against a set of rules
	 *
	 * Most rules from Laravel are implemented.
	 * 
	 * @link https://laravel.com/docs/5.4/validation#available-validation-rules
	 * @param  Array $all_rules List of rules, eg. 'login' => 'required|string'
	 * @param  Array &$errors   Filled with list of errors encountered
	 * @param  Array $source    Source of form data, if left empty or NULL,
	 * $_POST will be used
	 * @return boolean
	 */
	static public function validate(Array $all_rules, Array &$errors = null, Array $source = null)
	{
		if (is_null($errors))
		{
			$errors = [];
		}

		if (is_null($source))
		{
			$source = $_POST;
		}

		foreach ($all_rules as $key => $rules)
		{
			$rules = is_array($rules) ? $rules : self::parseRules($rules);

			foreach ($rules as $name => $params)
			{
				if (!self::validateRule($key, $name, $params, $source, $rules))
				{
					$errors[] = ['name' => $key, 'rule' => $name, 'params' => $params];
				}
			}
		}

		return count($errors) == 0 ? true : false;
	}
}
