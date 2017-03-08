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

/**
 * Smartyer: a lightweight Smarty template engine
 *
 * Smartyer is not really smarter, in fact it is dumber, it is merely replacing
 * some Smarty tags to PHP code. This may lead to hard to debug bugs as the
 * compiled PHP code may contain invalid syntax.
 *
 * Differences:
 * - UNSAFE! this is directly executing PHP code from the template,
 * you MUST NOT allow end users to edit templates. Consider Smartyer templates
 * as the same as PHP files.
 * - Auto HTML escaping of variables: {$name} will be escaped,
 * {$name|rot13} too.
 * Use {$name|raw} to disable auto-escaping, or {$name|escape:...} to specify
 * a custom escape method.
 * - Embedding variables in strings is not supported: "Hello $world" will 
 * display as is, same for "Hello `$world`"", use |args (= sprintf)
 * - Unsupported features: config files, $smarty. variables, cache, switch/case,
 * section, insert, {php}
 * - Much less default modifiers and functions
 *
 * @author  bohwaz  http://bohwaz.net/
 * @license BSD
 * @version 0.1
 */

namespace KD2;

class Smartyer
{
	/**
	 * Start delimiter, usually is {
	 * @var string
	 */
	protected $delimiter_start = '{';

	/**
	 * End delimiter, usually }
	 * @var string
	 */
	protected $delimiter_end = '}';

	/**
	 * Current template path
	 * @var string
	 */
	protected $template_path = null;

	/**
	 * Current compiled template path
	 * @var string
	 */
	protected $compiled_template_path = null;

	/**
	 * Content of the template source while compiling
	 * @var string
	 */
	protected $source = null;

	/**
	 * Variables assigned to the template
	 * @var array
	 */
	protected $variables = [];

	/**
	 * Functions registered to the template
	 * @var array
	 */
	protected $functions = [];

	/**
	 * Block functions registered to the template
	 * @var array
	 */
	protected $blocks = [];

	/**
	 * Modifier functions registered to the template
	 * @var array
	 */
	protected $modifiers = [
		'nl2br' => 'nl2br',
		'count' => 'count',
		'args' 	=> 'sprintf',
		'const' => 'constant',
		'trim' => 'trim',
		'rtrim' => 'rtrim',
		'ltrim' => 'ltrim',
		'cat' 	=> [__CLASS__, 'concatenate'],
		'escape' => [__CLASS__, 'escape'],
		'truncate' => [__CLASS__, 'truncate'],
		'replace' => [__CLASS__, 'replace'],
		'regex_replace' => [__CLASS__, 'replaceRegExp'],
		'date_format' => [__CLASS__, 'dateFormat'],
	];

	/**
	 * Compile function for unknown blocks
	 * @var array
	 */
	protected $compile_functions = [];

	/**
	 * Auto-escaping type (any type accepted by self::escape())
	 * @var string
	 */
	protected $escape_type = null;

	/**
	 * List of native PHP tags that don't require any argument
	 * 
	 * Note: switch/case is not supported because any white space
	 * between switch and the first case will produce and error
	 * see https://secure.php.net/manual/en/control-structures.alternative-syntax.php
	 * 
	 * @var array
	 */
	protected $raw_php_blocks = ['elseif', 'if', 'else', 'for', 'while'];

	/**
	 * Internal {foreachelse} stack to know when to use 'endif' instead of 'endforeach'
	 * for {/foreach} tags
	 * @var array
	 */
	protected $foreachelse_stack = [];

	/**
	 * Throws a parse error if an invalid block is encountered
	 * if set to FALSE, makes life easier for javascript, but this is a bit unreliable
	 * as some JS code might look like smarty code and produce errors,
	 * eg. variables: function () { $('.class').forEach(...
	 * some functions: if (true) { if (ok) }
	 * one solution is to append a comment line after opening brackets, or use {literal} blocks!
	 * @var boolean
	 */
	public $error_on_invalid_block = true;

	/**
	 * Global parent path to templates
	 * @var string
	 */
	static protected $cache_dir = null;

	/**
	 * Directory used for storing the compiled templates
	 * @var string
	 */
	static protected $templates_dir = null;

	/**
	 * Sets the path where compiled templates will be stored
	 * @param string $path
	 */
	static public function setCompileDir($path)
	{
		if (!is_dir($path))
		{
			throw new \RuntimeException($path . ' is not a directory.');
		}

		if (!is_writable($path))
		{
			throw new \RuntimeException($path . ' is not writeable by ' . __CLASS__);
		}

		self::$cache_dir = $path;
	}

	/**
	 * Sets the parent path containing all templates
	 * @param string $path
	 */
	static public function setTemplateDir($path)
	{
		if (!is_dir($path))
		{
			throw new \RuntimeException($path . ' is not a directory.');
		}

		if (!is_readable($path))
		{
			throw new \RuntimeException($path . ' is not readable by ' . __CLASS__);
		}

		self::$templates_dir = $path;
	}

	/**
	 * Creates a new template object
	 * @param string        $template Template filename or full path
	 * @param Smartyer|null $parent   Parent template object, useful to have a global
	 * template object with lots of assigns that will be used with all templates
	 */
	public function __construct($template = null, Smartyer &$parent = null)
	{
		if (is_null(self::$cache_dir))
		{
			throw new \LogicException('Compile dir not set: call ' . __CLASS__ . '::setCompileDir() first');
		}

		$this->template_path = !is_null($template) ? self::$templates_dir . DIRECTORY_SEPARATOR . $template : null;
		$this->compiled_template_path = self::$cache_dir . DIRECTORY_SEPARATOR . sha1($template) . '.phptpl';

		// Register parent functions and variables locally
		if ($parent instanceof Smartyer)
		{
			$copy = ['modifiers', 'blocks', 'functions', 'variables', 'escape_type', 'compile_functions'];

			foreach ($copy as $key)
			{
				$this->{$key} = $parent->{$key};
			}
		}
	}

	/**
	 * Returns Smartyer object built from a template string instead of a file path
	 * @param  string $string Template contents
	 * @return Smartyer
	 */
	static public function fromString($string, Smartyer &$parent = null)
	{
		$s = new Smartyer(null, $parent);
		$s->source = $string;
		$s->compiled_template_path = self::$cache_dir . DIRECTORY_SEPARATOR . sha1($string) . '.phptpl';
		return $s;
	}

	/**
	 * Display the current template or a new one if $template is supplied
	 * @param  string $template Template file name or full path
	 * @return Smartyer
	 */
	public function display($template = null)
	{
		echo $this->fetch($template);
		return $this;
	}

	/**
	 * Fetch the current template and returns the result it as a string,
	 * or fetch a new template if $template is supplied
	 * (for Smarty compatibility)
	 * @param  string $template Template file name or full path
	 * @return string
	 */
	public function fetch($template = null)
	{
		// Compatibility with legacy Smarty calls
		if (!is_null($template))
		{
			return (new Smartyer($template, $this))->fetch();
		}

		$time = @filemtime($this->compiled_template_path);

		if (!$time || (!is_null($this->template_path) && filemtime($this->template_path) > $time))
		{
			return $this->compile();
		}

		extract($this->variables, EXTR_REFS);

		ob_start();

		include $this->compiled_template_path;
		
		return ob_get_clean();
	}

	/**
	 * Precompiles all templates, without any execution (so no error, unless invalid template syntax)
	 * @param  string $path Path to templates dir
	 * @return void
	 */
	static public function precompileAll($templates_dir = null)
	{
		if (is_null($templates_dir))
		{
			$templates_dir = self::$templates_dir;

			if (is_null($templates_dir))
			{
				throw new \Exception('No template directory specified.');
			}
		}

		$dir = dir($templates_dir);

		// Compile all templates
		while ($file = $dir->read())
		{
			if ($file[0] == '.')
			{
				continue;
			}

			$file_path = $templates_dir . DIRECTORY_SEPARATOR . $file;

			if (is_dir($file_path))
			{
				self::precompileAll($file_path);
			}

			$tpl = new Smartyer(substr($file_path, strpos($file_path, $templates_dir)), $this);
			$tpl->compile();
		}
	}

	/**
	 * Sets the auto-escaping type for the current template
	 * @param string $type Escape type supported by self::escape()
	 */
	public function setEscapeType($type)
	{
		$this->escape_type = $type;
		return $this;
	}

	/**
	 * Assign a variable to the template
	 * @param  mixed  $name  Variable name or associative array of multiple variables
	 * @param  mixed  $value Variable value if variable name is a string
	 * @return Smartyer
	 */
	public function assign($name, $value = null)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>&$v)
			{
				$this->assign($k, $v);
			}

			return $this;
		}

		$this->variables[$name] = $value;
		return $this;
	}

	/**
	 * Assign a variable by reference to the template
	 * @param  mixed  $name  Variable name or associative array of multiple variables
	 * @param  mixed  &$value Reference
	 * @return Smartyer
	 */
	public function assign_by_ref($name, &$value)
	{
		$this->variables[$name] = $value;
		return $this;
	}

	/**
	 * Register a modifier function to the current template
	 * @param  string|array  $name     Modifier name or associative array of multiple modifiers
	 * @param  Callable|null $callback Valid callback if $name is a string
	 * @return Smartyer
	 */
	public function register_modifier($name, Callable $callback = null)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>&$v)
			{
				$this->register_modifier($k, $v);
			}

			return $this;
		}

		$this->modifiers[$name] = $callback;
		return $this;
	}

	/**
	 * Register a function to the current template
	 * @param  string|array  $name     Function name or associative array of multiple functions
	 * @param  Callable|null $callback Valid callback if $name is a string
	 * @return Smartyer
	 */
	public function register_function($name, Callable $callback)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>&$v)
			{
				$this->register_function($k, $v);
			}

			return $this;
		}

		$this->functions[$name] = $callback;
		return $this;
	}

	/**
	 * Register a block function to the current template
	 * @param  string|array  $name     Function name or associative array of multiple functions
	 * @param  Callable|null $callback Valid callback if $name is a string
	 * @return Smartyer
	 */
	public function register_block($name, Callable $callback)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>&$v)
			{
				$this->register_block($k, $v);
			}

			return $this;
		}

		$this->blocks[$name] = $callback;
		return $this;
	}

	/**
	 * Register a compile function that will be called for unknown blocks
	 *
	 * This offers a good way to extend the template language
	 *
	 * @param  string  $name     Function name
	 * @param  Callable|null $callback Valid callback
	 * @return Smartyer
	 */
	public function register_compile_function($name, Callable $callback)
	{
		// Try to bind the closure to the current smartyer object if possible
		$is_bindable = (new \ReflectionFunction(@\Closure::bind($callback, $this)))->getClosureThis() != null; 

		if ($is_bindable)
		{
			$this->compile_functions[$name] = $callback->bindTo($this, $this);
		}
		// This is a static closure, so no way to bind it
		else
		{
			$this->compile_functions[$name] = $callback;
		}

		return $this;
	}

	/**
	 * Compiles the current template to PHP code
	 */
	protected function compile()
	{
		if (is_null($this->source) && !is_null($this->template_path))
		{
			$this->source = file_get_contents($this->template_path);
		}

		$this->source = str_replace("\r", "", $this->source);

		$compiled = $this->parse($this->source);

		// Force new lines (this is to avoid PHP eating new lines after its closing tag)
		$compiled = preg_replace("/\?>\n/", "$0\n", $compiled);

		$compiled = '<?php /* Compiled from ' . $this->template_path . ' - ' . gmdate('Y-m-d H:i:s') . ' UTC */ '
			. 'if (!isset($_i)) { $_i = []; } if (!isset($_blocks)) { $_blocks = []; } ?>'
			. $compiled;

		// Write to temporary file
		file_put_contents($this->compiled_template_path . '.tmp', $compiled);

		$out = false;

		// We can catch most errors in the first run
		try {
			extract($this->variables, EXTR_REFS);

			ob_start();

			include $this->compiled_template_path . '.tmp';

			$out = ob_get_clean();
		}
		catch (\Exception $e)
		{
			ob_end_clean();

			if ($e instanceof Smartyer_Exception || $e->getFile() != $this->compiled_template_path . '.tmp')
			{
				throw $e;
			}

			// Finding the original template line number
			$compiled = explode("\n", $compiled);
			$compiled = array_slice($compiled, $e->getLine()-1);
			$compiled = implode("\n", $compiled);
			
			if (preg_match('!//#(\d+)\?>!', $compiled, $match))
			{
				$this->parseError($match[1], $e->getMessage(), $e);
			}
			else
			{
				throw $e;
			}
		}

		$this->source = null;

		// Atomic update if everything worked
		@unlink($this->compiled_template_path);
		rename($this->compiled_template_path . '.tmp', $this->compiled_template_path);

		unset($source, $compiled);

		return $out;
	}

	/**
	 * Parse the template and all tags
	 */
	protected function parse($source)
	{
		$anti = preg_quote($this->delimiter_start . $this->delimiter_end, '#');
		$pattern = '#' . preg_quote($this->delimiter_start, '#') . '((?:[^' . $anti . ']|(?R))*?)' . preg_quote($this->delimiter_end, '#') . '#i';

		$source = preg_split($pattern, $source, 0, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_DELIM_CAPTURE);

		unset($anti, $pattern);

		$compiled = '';
		$literal = false;

		foreach ($source as $i=>$block)
		{
			$pos = $block[1];
			$block = $block[0];
			$tblock = trim($block);

			if ($i % 2 == 0)
			{
				$compiled .= $block;
				continue;
			}

			// Comments
			if ($block[0] == '*' && substr($block, -1) == '*')
			{
				continue;
			}
			// Avoid matching JS blocks and others
			elseif ($tblock == 'ldelim')
			{
				$compiled .= $this->delimiter_start;
			}
			elseif ($tblock == 'rdelim')
			{
				$compiled .= $this->delimiter_end;
			}
			elseif ($tblock == 'literal')
			{
				$literal = true;
			}
			elseif ($tblock == '/literal')
			{
				$literal = false;
			}
			elseif ($literal)
			{
				$compiled .= $this->delimiter_start . $block . $this->delimiter_end;
			}
			// Closing blocks
			elseif ($tblock[0] == '/')
			{
				$compiled .= $this->parseClosing($pos, $tblock);
			}
			// Variables and strings
			elseif ($tblock[0] == '$' || $tblock[0] == '"' || $tblock[0] == "'")
			{
				$compiled .= $this->parseVariable($pos, $tblock);
			}
			elseif ($code = $this->parseBlock($pos, $tblock))
			{
				$compiled .= $code;
			}
			else
			{
				// Literal javascript / unknown block
				$compiled .= $this->delimiter_start . $block . $this->delimiter_end;
			}
		}

		unset($literal, $source, $i, $block, $tblock, $pos);

		return $compiled;
	}

	/**
	 * Parse smarty blocks and functions and returns PHP code
	 */
	protected function parseBlock($pos, $block)
	{
		// This is not a valid Smarty block, just assume it is PHP and reject any problem on the user
		if (!preg_match('/^(else if|.*?)(?:\s+(.+?))?$/s', $block, $match))
		{
			return '<?php ' . $block . '; ?>';
		}

		$name = trim(strtolower($match[1]));
		$raw_args = !empty($match[2]) ? trim($match[2]) : null;
		$code = '';

		unset($match);

		// alias
		if ($name == 'else if')
		{
			$name = 'elseif';
		}

		// Start counter
		if ($name == 'foreach')
		{
			$code = '$_i[] = 0; ';
		}

		// This is just PHP, this is easy
		if ($raw_args[0] == '(' && substr($raw_args, -1) == ')')
		{
			$raw_args = $this->parseMagicVariables($raw_args);
			$code .= $name . $raw_args . ':';
		}
		// Raw PHP tags with no enclosing bracket: enclose it in brackets if needed
		elseif (in_array($name, $this->raw_php_blocks))
		{
			if ($name == 'else')
			{
				$code = $name . ':';
			}
			elseif ($raw_args === '')
			{
				$this->parseError($pos, 'Invalid block {' . $name . '}: no arguments supplied');
			}
			else
			{
				$raw_args = $this->parseMagicVariables($raw_args);
				$code .= $name . '(' . $raw_args . '):';
			}
		}
		// Foreach with arguments
		elseif ($name == 'foreach')
		{
			array_push($this->foreachelse_stack, false);
			$args = $this->parseArguments($raw_args, $pos);

			$args['key'] = isset($args['key']) ? $this->getValueFromArgument($args['key']) : null;
			$args['item'] = isset($args['item']) ? $this->getValueFromArgument($args['item']) : null;
			$args['from'] = isset($args['from']) ? $this->getValueFromArgument($args['from']) : null;

			if (empty($args['item']))
			{
				$this->parseError($pos, 'Invalid foreach call: item parameter required.');
			}

			if (empty($args['from']))
			{
				$this->parseError($pos, 'Invalid foreach call: from parameter required.');
			}

			$key = $args['key'] ? '$' . $args['key'] . ' => ' : '';

			$code .= $name . ' (' . $args['from'] . ' as ' . $key . '$' . $args['item'] . '):';
		}
		// Special case for foreachelse (should be closed with {/if} instead of {/foreach})
		elseif ($name == 'foreachelse')
		{
			array_push($this->foreachelse_stack, true);
			$code = 'endforeach; $_i_count = array_pop($_i); ';
			$code .= 'if ($_i_count == 0):';
		}
		elseif ($name == 'include')
		{
			$args = $this->parseArguments($raw_args, $pos);

			if (empty($args['file']))
			{
				throw new Smartyer_Exception($pos, '{include} function requires file parameter.');
			}

			$file = $this->exportArgument($args['file']);
			unset($args['file']);

			if (count($args) > 0)
			{
				$assign = '$_s->assign(' . $this->exportArguments($args) . ');';
			}
			else
			{
				$assign = '';
			}

			$code = '$_s = new \KD2\Smartyer(' . $file . ', $this); ' . $assign . ' $_s->display(); unset($_s);';
		}
		else
		{
			if (array_key_exists($name, $this->blocks))
			{
				$args = $this->parseArguments($raw_args);
				$code = 'ob_start(); $_blocks[] = [' . var_export($name, true) . ', ' . $this->exportArguments($args) . '];'; // FIXME
			}
			elseif (array_key_exists($name, $this->functions))
			{
				$args = $this->parseArguments($raw_args);
				$code = 'echo $this->functions[' . var_export($name, true) . '](' . $this->exportArguments($args) . ');';
			}
			else
			{
				// Let's try the user-defined compile callbacks
				// and if none of them return something, we are out
				
				foreach ($this->compile_functions as $closure)
				{
					$code = call_user_func($closure, $pos, $block, $name, $raw_args);

					if ($code)
					{
						break;
					}
				}
			
				if (!$code)
				{
					if ($this->error_on_invalid_block)
					{
						$this->parseError($pos, 'Unknown function or block: ' . $name);
					}
					else
					{
						// Return raw source block, this is probably javascript
						return false;
					}
				}
			}
		}

		if ($name == 'foreach')
		{
			// Iteration counter
			$code .= ' $iteration =& $_i[count($_i)-1]; $iteration++;';
		}

		$code = '<?php ' . $code . ' //#' . $pos . '?>';

		unset($args, $name, $pos, $raw_args, $args, $block, $file);

		return $code;
	}

	/**
	 * Parse closing blocks and returns PHP code
	 */
	protected function parseClosing($pos, $block)
	{
		$code = '';
		$name = trim(substr($block, 1));

		switch ($name)
		{
			case 'foreach':
			case 'for':
			case 'while':
				// Close foreachelse
				if ($name == 'foreach' && array_pop($this->foreachelse_stack))
				{
					$name = 'if';
				}

				$code .= ' array_pop($_i);';
			case 'if':
				$code = 'end' . $name . ';' . $code;
				break;
			default:
			{
				if (array_key_exists($name, $this->blocks))
				{
					$code = '$_b = array_pop($_blocks); echo $this->blocks[$_b[0]](ob_get_clean(), $_b[1]);';
				}
				else
				{
					$this->parseError($pos, 'Unknown closing block: ' . $name);
				}
				break;
			}
		}

		$code = '<?php ' . $code . ' //#' . $pos . '?>';
		
		unset($name, $pos, $block);
		
		return $code;
	}

	/**
	 * Parse a Smarty variable and returns a PHP code
	 */
	protected function parseVariable($pos, $block)
	{
		$code = 'echo ' . $this->parseSingleVariable($block, $pos) . ';';
		$code = '<?php ' . $code . ' //#' . $pos . '?>';
			
		return $code;
	}

	/**
	 * Replaces $object.key and $array.key by method call to find the right value from $object and $array
	 * @param  string $str String where replacement should occur
	 * @return string
	 */
	protected function parseMagicVariables($str)
	{
		return preg_replace_callback('!(isset\s*\(\s*)?(\$[\w\d_]+)((?:\.[\w\d_]+)+)(\s*\))?!', function ($match) {
			$find = explode('.', $match[3]);
			return '$this->_magicVar(' . $match[2] . ', ' . var_export(array_slice($find, 1), true) . ')' . ($match[1] ? '' : @$match[4]);
		}, $str);
	}

	/**
	 * Throws an exception for the current template and hopefully giving the right line
	 * @param  integer $position Caret position in source code
	 * @param  string $message  Error message
	 * @param  Exception $previous Previous exception for the stack
	 * @throws Smartyer_Exception
	 */
	protected function parseError($position, $message, $previous = null)
	{
		$line = substr_count($this->source, "\n", 0, $position) + 1;
		throw new Smartyer_Exception($message, $this->template_path, $line, $previous);
	}

	/**
	 * Parse block arguments, this is similar to parsing HTML arguments
	 * @param  string $str List of arguments
	 * @param  integer $pos Caret position in source code
	 * @return array
	 */
	protected function parseArguments($str, $pos = null)
	{
		$args = [];
		$state = 0;
		$last_value = '';

		preg_match_all('/(?:"(?:\\.|[^\"])*?"|\'(?:\\.|[^\'])*?\'|(?>[^"\'=\s]+))+|[=]/i', $str, $match);

		foreach ($match[0] as $value)
		{
			if ($state == 0)
			{
				$name = $value;
			}
			elseif ($state == 1)
			{
				if ($value != '=')
				{
					$this->parseError($pos, 'Expecting \'=\' after \'' . $last_value . '\'');
				}
			}
			elseif ($state == 2)
			{
				if ($value == '=')
				{
					$this->parseError($pos, 'Unexpected \'=\' after \'' . $last_value . '\'');
				}

				$args[$name] = $this->parseSingleVariable($value, $pos, false);
				$name = null;
				$state = -1;
			}

			$last_value = $value;
			$state++;
		}

		unset($state, $last_value, $name, $str, $match);

		return $args;
	}

	/**
	 * Returns string value from a quoted or unquoted block argument
	 * @param  string $arg Extracted argument ({foreach from=$loop item="value"} => [from => "$loop", item => "\"value\""])
	 * @return string      Raw string
	 */
	protected function getValueFromArgument($arg)
	{
		if ($arg[0] == '"' || $arg[0] == "'")
		{
			return stripslashes(substr($arg, 1, -1));
		}

		return $arg;
	}

	/**
	 * Parse a variable, either from a {$block} or from an argument: {block arg=$bla|rot13}
	 * @param  string  $str     Variable string
	 * @param  integer $tpl_pos Character position in the source
	 * @param  boolean $escape  Auto-escape the variable output?
	 * @return string 			PHP code to return the variable
	 */
	protected function parseSingleVariable($str, $tpl_pos = null, $escape = true)
	{
		// Split by pipe (|) except if enclosed in quotes
		$modifiers = preg_split('/\|(?=(([^\'"]*["\']){2})*[^\'"]*$)/', $str);
		$var = array_shift($modifiers);

		// No modifiers: easy!
		if (count($modifiers) == 0)
		{
			$str = $this->exportArgument($str);

			if ($escape)
			{
				return 'self::escape(' . $str . ', $this->escape_type)';
			}
			else
			{
				return $str;
			}
		}

		$modifiers = array_reverse($modifiers);

		$pre = $post = '';

		foreach ($modifiers as &$modifier)
		{
			$_post = '';

			$pos = strpos($modifier, ':');

			// Arguments
			if ($pos !== false)
			{
				$mod_name = trim(substr($modifier, 0, $pos));
				$raw_args = substr($modifier, $pos+1);
				$arguments = [];

				// Split by two points (:) except if enclosed in quotes
				$arguments = preg_split('/\s*:\s*|("(?:\\\\.|[^"])*?"|\'(?:\\\\.|[^\'])*?\'|[^:\'"\s]+)/', trim($raw_args), 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
				$arguments = array_map([$this, 'exportArgument'], $arguments);

				$_post .= ', ' . implode(', ', $arguments);
			}
			else
			{
				$mod_name = trim($modifier);
			}

			// Disable autoescaping
			if ($mod_name == 'raw')
			{
				$escape = false;
				continue;
			}

			if ($mod_name == 'escape')
			{
				$escape = false;
			}

			// Modifiers MUST be registered at compile time
			if (!array_key_exists($mod_name, $this->modifiers))
			{
				$this->parseError($tpl_pos, 'Unknown modifier name: ' . $mod_name);
			}

			$post = $_post . ')' . $post;
			$pre .= '$this->modifiers[' . var_export($mod_name, true) . '](';
		}

		$var = $pre . $this->parseMagicVariables($var) . $post;
		
		unset($pre, $post, $arguments, $mod_name, $modifier, $modifiers, $pos, $_post);

		// auto escape
		if ($escape)
		{
			$var = 'self::escape(' . $var . ', $this->escape_type)';
		}

		return $var;
	}

	/**
	 * Export a string to a PHP value, depending of its type
	 *
	 * Quoted strings will be escaped, variables and true/false/null left as is,
	 * but unquoted strings containing [\w\d_-] will be quoted and escaped
	 * 
	 * @param  string $str 	String to export
	 * @return string 		PHP escaped string
	 */
	protected function exportArgument($str)
	{
		$raw_values = ['true', 'false', 'null'];

		if ($str[0] == '$')
		{
			$str = $this->parseMagicVariables($str);
		}
		elseif ($str[0] == '"' || $str[0] == "'")
		{
			$str = var_export($this->getValueFromArgument($str), true);
		}
		elseif (!in_array(strtolower($str), $raw_values) && preg_match('/^[\w\d_-]+$/i', $str))
		{
			$str = var_export($str, true);
		}

		return $str;
	}

	/**
	 * Export an array to a string, like var_export but without escaping of strings
	 *
	 * This is used to reference variables and code in arrays
	 * 
	 * @param  array   $args      Arguments to export
	 * @return string
	 */
	protected function exportArguments(array $args)
	{
		$out = '[';

		foreach ($args as $key=>$value)
		{
			$out .= var_export($key, true) . ' => ' . trim($value) . ', ';
		}

		$out .= ']';

		return $out;
	}

	/**
	 * Retrieve a magic variable like $object.key or $array.key.subkey
	 * @param  mixed $var   Variable to look into (object or array)
	 * @param  array $keys  List of keys to look for
	 * @return mixed        NULL if the key doesn't exists, or the value associated to the key
	 */
	protected function _magicVar($var, array $keys)
	{
		while ($key = array_shift($keys))
		{
			if (is_object($var))
			{
				// Test for constants
				if (defined(get_class($var) . '::' . $key))
				{
					return constant(get_class($var) . '::' . $key);
				}

				if (!property_exists($var, $key))
				{
					return null;
				}
				
				$var = $var->$key;
			}
			elseif (is_array($var))
			{
				if (!array_key_exists($key, $var))
				{
					return null;
				}

				$var = $var[$key];
			}
		}

		return $var;
	}

	/**
	 * Native default escape modifier
	 */
	static protected function escape($str, $type = 'html')
	{
		if (is_array($str) || is_object($str))
		{
			throw new \InvalidArgumentException('Invalid argument type for escape modifier: ' . gettype($str));
		}

		switch ($type)
		{
			case 'html':
			case null:
				return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
			case 'xml':
				return htmlspecialchars($str, ENT_XML1, 'UTF-8');
			case 'htmlall':
			case 'entities':
				return htmlentities($str, ENT_QUOTES, 'UTF-8');
			case 'url':
				return rawurlencode($str);
			case 'quotes':
				return addslashes($str);
			case 'hex':
				return preg_replace_callback('/./', function ($match) {
					return '%' . ord($match[0]);
				}, $str);
			case 'hexentity':
				return preg_replace_callback('/./', function ($match) {
					return '&#x' . ord($match[0]) . ';';
				}, $str);
			case 'mail':
				return str_replace('.', '[dot]', $str);
			case 'json':
				return json_encode($str);
			case 'js':
			case 'javascript':
				return strtr($str, [
					"\x08" => '\\b', "\x09" => '\\t', "\x0a" => '\\n', 
					"\x0b" => '\\v', "\x0c" => '\\f', "\x0d" => '\\r', 
					"\x22" => '\\"', "\x27" => '\\\'', "\x5c" => '\\'
				]);
			default:
				return $str;
		}
	}

	/**
	 * Simple wrapper for str_replace as modifier
	 */
	static protected function replace($str, $a, $b)
	{
		return str_replace($a, $b, $str);
	}

	static protected function replaceRegExp($str, $a, $b)
	{
		return preg_replace($a, $b, $str);
	}

	/**
	 * UTF-8 aware intelligent substr
	 * @param  string  $str         UTF-8 string
	 * @param  integer $length      Maximum string length
	 * @param  string  $placeholder Placeholder text to append at the string if it has been cut
	 * @param  boolean $strict_cut  If true then will cut in the middle of words
	 * @return string 				String cut to $length or shorter
	 * @example |truncate:10:" (click to read more)":true
	 */
	static protected function truncate($str, $length = 80, $placeholder = '…', $strict_cut = false)
	{
		// Don't try to use unicode if the string is not valid UTF-8
		$u = preg_match('//u', $str) ? 'u' : '';

		// Shorter than $length + 1
		if (!preg_match('/^.{' . ((int)$length + 1) . '}/' . $u, $str))
		{
			return $str;
		}

		// Cut at 80 characters
		$str = preg_replace('/^(.{' . (int)$length . '}).*$/' . $u, '$1', $str);

		if (!$strict_cut)
		{
			$str = preg_replace('/([\s.,:;!?]).*?$/' . $u, '$1', $str);
		}

		return trim($str) . $placeholder;
	}

	/**
	 * Simple strftime wrapper
	 * @example |date_format:"%F %Y"
	 */
	static protected function dateFormat($date, $format = '%b, %e %Y')
	{
		if (!is_numeric($date))
		{
			$date = strtotime($date);
		}

		if (strpos('DATE_', $format) === 0 && defined($format))
		{
			return date(constant($format), $date);
		}

		return strftime($format, $date);
	}

	/**
	 * Concatenate strings (use |args instead!)
	 * @return string
	 * @example $var|cat:$b:"ok"
	 */
	static protected function concatenate()
	{
		return implode('', func_get_args());
	}

	static protected function pagination($params)
	{
		extract($params);

		if (!isset($count) || !isset($current) || !isset($per_page) || !isset($url))
		{
			throw new Smartyer_Exception('Missing parameter count, current or per_page.');
		}

		if (strpos($url, '%d') === false)
		{
			$url .= '%d';
		}

		$max_page = ceil($count / $per_page);

		// No pagination
		if ($max_page <= 1)
		{
			return '';
		}

		$links = '<ul class="pagination">';

		if ($current > 1)
		{
			$links .= '<li class="prev"><a href="' . sprintf($url, $current - 1) . '">&larr;</a></li>';
		}

		if ($max_page > 10)
		{
			$start = max(1, $current - 4);
			$end = max($max_page, $start + 9);

			if ($start > 1)
			{
				$links .= '<li class="first"><a href="' . sprintf($url, 1) . $url . '">1</a></li>';
				$links .= '<li class="etc">…</li>';
			}

			for ($i = $start; $i <= $end; $i++)
			{
				$links .= '<li' . ($current == $i ? ' class="current"' : '') . '><a href="' . sprintf($url, $i) . '">' . $i . '</a></li>';
			}

			if ($end < $max_page)
			{
				$links .= '<li class="etc">…</li>';
				$links .= '<li class="last"><a href="' . sprintf($url, $max_page) . '">' . $max_page . '</a></li>';
			}
		}
		else
		{
			for ($i = 1; $i <= $max_page; $i++)
			{
				$links .= '<li' . ($current == $i ? ' class="current"' : '') . '><a href="' . sprintf($url, $i) . '">' . $i . '</a></li>';
			}
		}

		if ($current < $max_page)
		{
			$links .= '<li class="prev"><a href="' . sprintf($url, $current + 1) . '">&larr;</a></li>';
		}

		$links .= '</ul>';

		return $links;
	}
}

/**
 * Templates exceptions
 */
class Smartyer_Exception extends \Exception
{
	public function __construct($message, $file, $line, $previous)
	{
		parent::__construct($message, 0, $previous);
		$this->file = is_null($file) ? '::fromString() template' : $file;
		$this->line = $line;
	}
}

