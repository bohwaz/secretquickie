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

/**
 * Simple error and exception handler
 *
 * When enabled (with ErrorManager::enable(ErrorManager::DEVELOPMENT)) it will
 * catch any error, warning or exception and display it along with useful debug
 * information. If enabled it will also log the errors to a file and/or send
 * every error by email.
 *
 * In production mode no details are given, but a unique reference to the log
 * or email is displayed.
 * 
 * This is similar in a way to http://tracy.nette.org/
 *
 * @author  bohwaz <http://bohwaz.net/>
 * @package KD2fw
 * @license BSD
 */
class ErrorManager
{
	/**
	 * Prod/dev modes
	 */
	const PRODUCTION = 1;
	const DEVELOPMENT = 2;

	/**
	 * Term colors
	 */
	const RED = '[1;41m';
	const RED_FAINT = '[1m';
	const YELLOW = '[33m';

	/**
	 * true = catch exceptions, false = do nothing
	 * @var null
	 */
	static protected $enabled = null;

	/**
	 * HTML template used for displaying production errors
	 * @var string
	 */
	static protected $production_error_template = '<!DOCTYPE html><html><head><title>Internal server error</title>
		<style type="text/css">
		body {font-family: sans-serif; }
		code, p, h1 { max-width: 400px; margin: 1em auto; display: block; }
		code { text-align: right; color: #666; }
		a { color: blue; }
		</style></head><body><h1>Server error</h1><p>Sorry but the server encountered an internal error and was unable 
		to complete your request. Please try again later.</p>
		<if(email)><p>The webmaster has been noticed and this will be fixed ASAP.</p></if>
		<if(log)><code>Error reference: <b>{$ref}</b></code></if>
		<p><a href="/">&larr; Go back to the homepage</a></p>
		</body></html>';

	/**
	 * E-Mail address where to send errors
	 * @var boolean
	 */
	static protected $email_errors = false;

	/**
	 * Custom exception handlers
	 * @var array
	 */
	static protected $custom_handlers = [];

	/**
	 * Additional debug environment information that should be included in logs
	 * @var array
	 */
	static protected $debug_env = [];

	/**
	 * Does the terminal support ANSI colors
	 * @var boolean
	 */
	static protected $term_color = false;

	/**
	 * Will be set to true when catching an exception to avoid double catching
	 * with the shutdown function
	 * @var boolean
	 */
	static protected $catching = false;

	/**
	 * Used to store timers and memory consumption
	 * @var array
	 */
	static protected $run_trace = [];

	/**
	 * Handles PHP shutdown on fatal error to be able to catch the error
	 * @return void
	 */
	static public function shutdownHandler()
	{
		// Stop here if disabled or if the script ended with an exception
		if (!self::$enabled || self::$catching)
			return false;

		$error = error_get_last();
		
		if (in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR, E_USER_ERROR], TRUE))
		{
			self::exceptionHandler(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), false);
		}
	}

	/**
	 * Internal error handler to throw them as exceptions
	 * (private use)
	 */
	static public function errorHandler($severity, $message, $file, $line)
	{
		if (!(error_reporting() & $severity)) {
			// Don't report this error (for example @unlink)
			return;
		}

		$message = self::errorTypeName($severity) . ': ' . $message;

		// Catch ASSERT_BAIL errors differently because throwing an exception
		// in this case results in an execution shutdown, and shutdown handler
		// isn't even called. See https://bugs.php.net/bug.php?id=53619
		if (assert_options(ASSERT_ACTIVE) && assert_options(ASSERT_BAIL) && substr($message, 0, 18) == 'Warning: assert():')
		{
			$message .= ' (ASSERT_BAIL detected)';
			return self::exceptionHandler(new \ErrorException($message, 0, $severity, $file, $line));
		}

		throw new \ErrorException($message, 0, $severity, $file, $line);
		return true;
	}

	/**
	 * Print to terminal with colors if available
	 * @param  string $message Message to print
	 * @param  const  $pipe    UNIX pipe to outpit to (STDOUT, STDERR...)
	 * @param  string $color   One of self::COLOR constants
	 * @return void
	 */
	static public function termPrint($message, $pipe = STDOUT, $color = null)
	{
		if ($color)
		{
			$message = chr(27) . $color . $message . chr(27) . "[0m";
		}

		fwrite($pipe, $message . PHP_EOL);
	}

	/**
	 * Main exception handler
	 * @param  object  $e    Exception or Error (PHP 7) object
	 * @param  boolean $exit Exit the script at the end
	 * @return void
	 */
	static public function exceptionHandler($e, $exit = true)
	{
		self::$catching = true;
		
		foreach (self::$custom_handlers as $class=>$callback)
		{
			if ($e instanceOf $class)
			{
				call_user_func($callback, $e);
				$e = false;
				break;
			}
		}

		if ($e !== false)
		{
			$file = self::getFileLocation($e->getFile());
			$ref = null;
			$log = self::exceptionAsLog($e, $ref);

			// Log exception to file
			if (ini_get('error_log'))
			{
				error_log($log);
			}

			// Log exception to email
			if (self::$email_errors)
			{
				// From: sender
				$from = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : basename($_SERVER['DOCUMENT_ROOT']);

				$headers = [
					'Subject'	=>	'Error ref ' . $ref,
					'From' 		=>	'"' . $from . '" <' . self::$email_errors . '>',
				];

				error_log($log, 1, self::$email_errors, implode("\r\n", $headers));
			}

			// Disable any output if it was buffering
			if (ob_get_level())
			{
				ob_end_clean();
			}

			if (PHP_SAPI == 'cli')
			{
				self::termPrint(get_class($e) . ' [Code: ' . $e->getCode() . ']', STDERR, self::RED);
				self::termPrint($e->getMessage(), STDERR, self::RED_FAINT);
				self::termPrint('Line ' . $e->getLine() . ' in ' . $file, STDERR, self::YELLOW);

				// Ignore the error stack belonging to ErrorManager
				if (!(count($e->getTrace()) > 0 && ($t = $e->getTrace()[0]) 
					&& isset($t['class']) && $t['class'] === __CLASS__ 
					&& ($t['function'] === 'shutdownHandler' || $t['function'] === 'errorHandler')))
				{
					self::termPrint(PHP_EOL . $e->getTraceAsString(), STDERR);
				}
			}
			else if (self::$enabled == self::PRODUCTION)
			{
				self::htmlProduction($ref);
			}
			else
			{
				// Display debug
				echo ini_get('error_prepend_string');

				while ($e)
				{
					self::htmlException($e);
					$e = $e->getPrevious();
				}

				self::htmlEnvironment();

				echo ini_get('error_append_string');
			}
		}

		if ($exit)
		{
			exit(1);
		}
	}

	/**
	 * Export the exception and stack trace as a text log
	 * @param  object $e    Exception
	 * @param  string &$ref A unique reference that will be assigned to this 
	 * log and can be used in email or production display
	 * @return string       Exception log text
	 */
	static public function exceptionAsLog($e, &$ref)
	{
		$out = '';

		if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI']))
		{
			$proto = empty($_SERVER['HTTPS']) ? 'http' : 'https';
			$out .= $proto . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . PHP_EOL . PHP_EOL;
		}

		while ($e)
		{
			$out .= get_class($e) 
				. ' [Code ' . $e->getCode() . '] '
				. $e->getMessage() . PHP_EOL
				. self::getFileLocation($e->getFile())
				 . ':' . $e->getLine() . PHP_EOL . PHP_EOL;

			$out .= $e->getTraceAsString();
			$out .= PHP_EOL . PHP_EOL;

			$e = $e->getPrevious();
		}

		// Include extra debug info
		foreach (self::$debug_env as $key=>$value)
		{
			$out .= $key . ': ' . $value . PHP_EOL;
		}

		$out .= 'PHP version: ' . phpversion() . PHP_EOL;

		// Usual environment
		foreach ($_SERVER as $key=>$value)
		{
			if (is_array($value))
				$value = json_encode($value);

			$out .= $key . ': ' . $value . PHP_EOL;
		}

		$out = str_replace("\r", '', $out);

		// Generate (almost) unique reference
		$ref = base_convert(substr(sha1($out), 0, 10), 16, 36);

		$out = PHP_EOL . str_repeat('=', 25) . ' Error ref ' . $ref . ' ' . str_repeat('=', 25) . PHP_EOL . PHP_EOL . $out;

		return $out;
	}

	/**
	 * Return file location without the document root
	 * @param  string $file Complete file path
	 * @return string 		File path without the document root
	 */
	static protected function getFileLocation($file)
	{
		return str_replace($_SERVER['DOCUMENT_ROOT'], '', $file);
	}

	/**
	 * Displays an exception as HTML debug page
	 * @param  object $e Exception
	 * @return void
	 */
	static public function htmlException($e)
	{
		$class = get_class($e);

		if (in_array($class, ['ErrorException', 'Error']))
			$class = 'PHP error';

		$file = self::getFileLocation($e->getFile());

		echo '<section>';
		echo '<header><h1>' . $class . '</h1><h2>' . htmlspecialchars($e->getMessage()) . '</h2>';
		echo '<h3>' . htmlspecialchars($file) . ':' . $e->getLine() . '</h3>';
		echo '</header><article>';
		echo self::htmlSource($e->getFile(), $e->getLine());
		echo '</article>';

		foreach ($e->getTrace() as $i=>$t)
		{
			// Ignore the error stack from ErrorManager
			if (isset($t['class']) && $t['class'] === __CLASS__ && ($t['function'] === 'shutdownHandler' || $t['function'] === 'errorHandler'))
				continue;

			$nb_args = count($t['args']);

			$function = $t['function'];

			// Add class name to function
			if (isset($t['class']))
				$function = $t['class'] . $t['type'] . $function;

			echo '<article><h3>';

			// Sometimes the file/line is not specified
			if (isset($t['file']) && isset($t['line']))
			{
				$file = self::getFileLocation($t['file']);
				$dir = dirname($file);
				$dir = $dir == '/' ? $dir : $dir . '/';
				echo htmlspecialchars($dir) . '<b>' . htmlspecialchars(basename($file)) . '</b>:<i>' . (int) $t['line'] . '</i> ';
			}

			echo '</h3><h4>&rarr; ' . htmlspecialchars($function) . ' <i>(' . (int) $nb_args . ' arg.)</i></h4>';

			// Display call arguments
			if ($nb_args)
			{
				echo '<table>';

				// Find arguments variables names via reflection
				try {
					if (isset($t['class']))
						$r = new \ReflectionMethod($t['class'], $t['function']);
					else
						$r = new \ReflectionFunction($t['function']);
					
					$params = $r->getParameters();
				}
				catch (\Exception $e) {
					$params = [];
				}

				foreach ($t['args'] as $name => $value)
				{
					if (array_key_exists($name, $params))
					{
						$name = '$' . $params[$name]->name;
					}

					echo '<tr><th>' . htmlspecialchars($name) . '</th><td><pre>' . htmlspecialchars(self::dump($value)) . '</pre></td>';
				}

				echo '</table>';
			}

			// Display source code
			if (isset($t['file']) && isset($t['line']))
				echo self::htmlSource($t['file'], $t['line']);

			echo '</article>';
		}

		echo '</section>';
	}

	/**
	 * Display environment information
	 * @return void
	 */
	static public function htmlEnvironment()
	{

	}

	/**
	 * Source code display
	 * @param  string $file File location
	 * @param  integer $line Line to highlight
	 * @return string       HTML display of file
	 */
	static public function htmlSource($file, $line)
	{
		$out = '';
		$start = max(0, $line - 5);

		$file = new \SplFileObject($file);
		$file->seek($start);

		for ($i = $start + 1; $i < $start+10; $i++)
		{
			if ($file->eof())
				break;

			$code = trim($file->current(), "\r\n");
			$html = '<b>' . ($i) . '</b>' . htmlspecialchars($code, ENT_QUOTES);

			if ($i == $line)
			{
				$html = '<u>' . $html . '</u>';
			}

			$out .= $html . PHP_EOL;
			$file->next();
		}

		return '<pre><code>' . $out . '</code></pre>';
	}

	static public function htmlProduction($ref)
	{
		if (!headers_sent())
		{
			header('HTTP/1.1 500 Internal Server Error', true, 500);
		}

		$out = self::$production_error_template;
		$out = strtr($out, [
			'{$ref}' => $ref,
		]);

		$out = preg_replace_callback('!<if\((email|log)\)>(.*?)</if>!is', function ($match) {
			$criteria = ($match[1] == 'email') ? self::$email_errors : ini_get('error_log');
			return (bool) $criteria ? $match[2] : '';
		}, $out);

		echo $out;
	}

	public static function errorTypeName($type)
	{
		$types = [
			E_ERROR => 'Fatal error',
			E_USER_ERROR => 'User error',
			E_RECOVERABLE_ERROR => 'Recoverable error',
			E_CORE_ERROR => 'Core error',
			E_COMPILE_ERROR => 'Compile error',
			E_PARSE => 'Parse error',
			E_WARNING => 'Warning',
			E_CORE_WARNING => 'Core warning',
			E_COMPILE_WARNING => 'Compile warning',
			E_USER_WARNING => 'User warning',
			E_NOTICE => 'Notice',
			E_USER_NOTICE => 'User notice',
			E_STRICT => 'Strict standards',
			E_DEPRECATED => 'Deprecated',
			E_USER_DEPRECATED => 'User deprecated',
		];
		
		return array_key_exists($type, $types) ? $types[$type] : 'Unknown error';
	}

	/**
	 * Enable error manager
	 * @param  integer $type Type of error management (ErrorManager::PRODUCTION or ErrorManager::DEVELOPMENT)
	 * @return void
	 */
	static public function enable($type = self::DEVELOPMENT)
	{
		if (self::$enabled)
			return true;

		self::$enabled = $type;

		self::$term_color = function_exists('posix_isatty') && @posix_isatty(STDOUT);

		ini_set('display_errors', false);
		ini_set('log_errors', false);
		ini_set('html_errors', false);
		error_reporting($type == self::DEVELOPMENT ? -1 : E_ALL & ~E_DEPRECATED & ~E_STRICT);

		if ($type == self::DEVELOPMENT && PHP_SAPI != 'cli')
		{
			self::setHtmlHeader('<!DOCTYPE html><meta charset="utf-8" /><style type="text/css">
			body { font-family: sans-serif; } * { margin: 0; padding: 0; }
			u, code b, i, h3 { font-style: normal; font-weight: normal; text-decoration: none; }
			#icn { color: #fff; font-size: 2em; float: right; margin: 1em; padding: 1em; background: #900; border-radius: 50%; }
			section header { background: #fdd; padding: 1em; }
			section article { margin: 1em; }
			section article h3, section article h4 { font-size: 1em; font-family: mono; }
			code { border: 1px dotted #ccc; display: block; }
			code b { margin-right: 1em; color: #999; }
			code u { display: block; background: #fcc; }
			table { border-collapse: collapse; margin: 1em; } td, th { border: 1px solid #ccc; padding: .2em .5em; text-align: left; 
			vertical-align: top; }
			</style>
			<pre id="icn"> \__/<br /> (xx)<br />//||\\\\</pre>');
		}

		register_shutdown_function([__CLASS__, 'shutdownHandler']);
		set_exception_handler([__CLASS__, 'exceptionHandler']);

		// For PHP7 we don't need to throw ErrorException as all errors are thrown as Error
		// see https://secure.php.net/manual/en/language.errors.php7.php
		if (!class_exists('\Error', false))
		{
			set_error_handler([__CLASS__, 'errorHandler']);
		}

		if ($type == self::DEVELOPMENT)
		{
			self::startTimer('_global');
		}
	}

	/**
	 * Reset error management to PHP defaults
	 * @return boolean
	 */
	static public function disable()
	{
		self::$enabled = false;

		ini_set('error_prepend_string', null);
		ini_set('error_append_string', null);
		ini_set('log_errors', false);
		ini_set('display_errors', false);
		ini_set('error_reporting', E_ALL & ~E_DEPRECATED & ~E_STRICT);

		restore_error_handler();
		return restore_exception_handler();
	}

	/**
	 * Sets a microsecond timer to track time and memory usage
	 * @param string $name Timer name
	 */
	static public function startTimer($name)
	{
		self::$run_trace[$name] = [microtime(true), memory_get_usage()];
	}

	/**
	 * Stops a timer and return time spent and memory used
	 * @param string $name Timer name
	 */
	static public function stopTimer($name)
	{
		self::$run_trace[$name][0] = microtime(true) - self::$run_trace[$name][0];
		self::$run_trace[$name][1] = memory_get_usage() - self::$run_trace[$name][1];
		return self::$run_trace[$name];
	}

	/**
	 * Sets a log file to record errors
	 * @param string $file Error log file
	 */
	static public function setLogFile($file)
	{
		ini_set('log_errors', true);
		return ini_set('error_log', $file);
	}

	/**
	 * Sets an email address that should receive the logs
	 * Set to FALSE to disable email sending (default)
	 * @param string $email Email address
	 */
	static public function setEmail($email)
	{
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			throw new \InvalidArgumentException('Invalid email address: ' . $email);
		}

		self::$email_errors = $email;
	}

	/**
	 * Add an extra variable to debug environment reported by errors
	 * @param mixed $env Variable content, could be application version, or an array of information...
	 */
	static public function setExtraDebugEnv($env)
	{
		self::$debug_env = $env;
	}

	/**
	 * Set the HTML header used by the debug error page
	 * @param string $html HTML header
	 */
	static public function setHtmlHeader($html)
	{
		ini_set('error_prepend_string', $html);
	}

	/**
	 * Set the HTML footer used by the debug error page
	 * @param string $html HTML footer
	 */
	static public function setHtmlFooter($html)
	{
		ini_set('error_append_string', $html);
	}

	/**
	 * Set the content of the HTML template used to display an error in production
	 * {$ref} will be replaced by the error reference if log or email is enabled
	 * <if(email)>...</if> block will be removed if email reporting is disabled
	 * <if(log)>...</if> block will be removed if log reporting is disabled
	 * @param string $html HTML template
	 */
	static public function setProductionErrorTemplate($html)
	{
		self::$production_error_template = $html;
	}

	static public function setCustomExceptionHandler($class, Callable $callback)
	{
		self::$custom_handlers[$class] = $callback;
	}

	/**
	 * Copy of var_dump but returns a string instead of a variable
	 * @param  mixed  $var   variable to dump
	 * @param  integer $level Indentation level (internal use)
	 * @return string
	 */
	static public function dump($var, $level = 0)
	{
		switch (gettype($var))
		{
			case 'boolean':
				return 'bool(' . ($var ? 'true' : 'false') . ')';
			case 'integer':
				return 'int(' . $var . ')';
			case 'double':
				return 'float(' . $var . ')';
			case 'string':
				return 'string(' . strlen($var) . ') "' . $var . '"';
			case 'NULL':
				return 'NULL';
			case 'resource':
				return 'resource(' . (int)$var . ') of type (' . get_resource_type($var) . ')';
			case 'array':
			case 'object':
				if (is_object($var))
				{
					$out = 'object(' . get_class($var) . ') (' . count((array) $var) . ') {' . PHP_EOL;
				}
				else
				{
					$out = 'array(' . count((array) $var) . ') {' . PHP_EOL;
				}

				$level++;

				foreach ($var as $key=>$value)
				{
					$out .= str_repeat(' ', $level * 2);
					$out .= is_string($key) ? '["' . $key . '"]' : '[' . $key . ']';
					$out .= '=> ' . self::dump($value, $level) . PHP_EOL;
				}

				$out .= str_repeat(' ', --$level * 2) . '}';
				return $out;
			default:
				return gettype($var);
		}
	}


}