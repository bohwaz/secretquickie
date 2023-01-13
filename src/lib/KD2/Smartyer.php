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
 * @version 0.2
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
	 * Current template file name / path
	 */
	protected $template;

	/**
	 * Current template complete path (includes ->root_dir)
	 * @var string
	 */
	protected $template_path;

	/**
	 * Current compiled template path
	 * @var string
	 */
	protected $compiled_template_path;

	/**
	 * Content of the template source while compiling
	 * @var string
	 */
	protected $source;

	/**
	 * Parent template (if any)
	 * @var self
	 */
	public $parent;

	/**
	 * Variables assigned to the template
	 * @var array
	 */
	protected $variables = [];

	/**
	 * Functions registered to the template
	 * @var array
	 */
	protected $functions = [
		'assign' => [__CLASS__, 'templateAssign'],
	];

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
	protected $escape_type;

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
	 * Default namespace used in templates
	 * @var string
	 */
	protected $namespace;

	/**
	 * Directory used to store the compiled code
	 * @var string
	 */
	protected $compiled_dir;

	/**
	 * Root directory to child templates
	 * @var string
	 */
	protected $templates_dir;

	/**
	 * Sets the path where compiled templates will be stored
	 * @param string $path
	 */
	public function setCompiledDir($path)
	{
		if (!is_dir($path))
		{
			throw new \RuntimeException($path . ' is not a directory.');
		}

		if (!is_writable($path))
		{
			throw new \RuntimeException($path . ' is not writeable by ' . __CLASS__);
		}

		$this->compiled_dir = $path;
	}

	/**
	 * Sets the default path containing all templates
	 * @param string $path
	 */
	public function setTemplatesDir($path)
	{
		if (!is_dir($path))
		{
			throw new \RuntimeException($path . ' is not a directory.');
		}

		if (!is_readable($path))
		{
			throw new \RuntimeException($path . ' is not readable by ' . __CLASS__);
		}

		$this->templates_dir = $path;
	}

	/**
	 * Sets the namespace used by the template code
	 * @param string $namespace
	 */
	public function setNamespace($namespace)
	{
		$this->namespace = $namespace;
	}

	/**
	 * Creates a new template object
	 * @param string        $template Template filename or full path
	 * @param Smartyer|null $parent   Parent template object, useful to have a global
	 * template object with lots of assigns that will be used with all templates
	 */
	public function __construct($template = null, Smartyer &$parent = null)
	{
		$this->template = $template;

		// Register parent functions and variables locally
		if ($parent instanceof Smartyer)
		{
			$copy = ['modifiers', 'blocks', 'functions', 'escape_type', 'compile_functions', 'namespace', 'compiled_dir', 'templates_dir'];

			foreach ($copy as $key)
			{
				$this->{$key} = &$parent->{$key};
			}

			// Do not reference variables, we want their scope to stay inside the other template
			$this->variables= $parent->variables;
			$this->parent = $parent;
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

	protected function _isPathRelative(string $path): bool
	{
		if (substr($this->template, 0, 1) == '/') {
			return false;
		}

		if (substr($this->template, 0, 7) == 'phar://') {
			return false;
		}

		if (PHP_OS_FAMILY == 'Windows' && ctype_alpha(substr($path, 0, 1)) && substr($path, 1, 2) == ':\\') {
			return false;
		}

		return true;
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

		if (is_null($this->compiled_dir))
		{
			throw new \LogicException('Compile dir not set: call ' . __CLASS__ . '->setCompiledDir() first');
		}

		if (!is_null($this->template))
		{
			// Don't prepend templates_dir for phar and absolute paths
			if (!$this->_isPathRelative($this->template))
			{
				$this->template_path = $this->template;
			}
			else
			{
				$this->template_path = $this->templates_dir . DIRECTORY_SEPARATOR . $this->template;
			}
		}

		if (!is_null($this->template_path) && (!is_file($this->template_path) || !is_readable($this->template_path)))
		{
			throw new \RuntimeException('Template file doesn\'t exist or is not readable: ' . $this->template_path);
		}

		if (is_null($this->compiled_template_path)) {
			if (is_null($this->template_path))
			{
				// Anonymous templates
				$hash = sha1($this->source . $this->namespace);
			}
			else
			{
				$hash = sha1($this->template_path . $this->namespace);
			}

			$this->compiled_template_path = $this->compiled_dir . DIRECTORY_SEPARATOR . $hash . '.tpl.php';
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
	static public function precompileAll($templates_dir)
	{
		if (!is_dir($templates_dir))
		{
			throw new \RuntimeException('The template directory specified is not a directory: ' . $templates_dir);
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

			$tpl = new Smartyer(substr($file_path, strpos($file_path, $templates_dir)));
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

	public function getEscapeType()
	{
		return $this->escape_type;
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
	 * Return assigned variables
	 * @param  string|null $name name of the variable, if NULL then all variables are returned
	 * @return mixed
	 */
	public function getTemplateVars($name = null)
	{
		if (!is_null($name))
		{
			if (array_key_exists($name, $this->variables))
			{
				return $this->variables[$name];
			}
			else
			{
				return null;
			}
		}

		return $this->variables;
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
	public function register_function($name, Callable $callback = null)
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
	public function register_block($name, Callable $callback = null)
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
	public function register_compile_function(string $name, callable $callback)
	{
		$this->compile_functions[$name] = $callback;
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

		// Keep a trace of the source for debug purposes
		$prefix = '<?php /* Compiled from ' . $this->template_path . ' - ' . gmdate('Y-m-d H:i:s') . ' UTC */ ';

		// Apply namespace
		if ($this->namespace)
		{
			$prefix .= sprintf("\nnamespace %s;\n", $this->namespace);
		}

		// Stop execution if not in the context of Smartyer
		// this is to avoid potential execution of template code outside of Smartyer
		$prefix .= 'if (!isset($this) || !is_object($this) || (!($this instanceof \KD2\Smartyer) && !is_subclass_of($this, \'\KD2\Smartyer\', true))) { die("Wrong call context."); } ';

		// Initialize useful variables
		$prefix .= 'if (!isset($_i)) { $_i = []; } if (!isset($_blocks)) { $_blocks = []; } ?>';

		$compiled = $prefix . $compiled;

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

		// Atomic update if everything worked, destination will be overwritten
		rename($this->compiled_template_path . '.tmp', $this->compiled_template_path);

		unset($compiled);

		return $out;
	}

	/**
	 * Parse the template and all tags
	 */
	protected function parse($source)
	{
		$literals = [];

		$pattern = sprintf('/%s\*.*?\*%2$s|<\?(?:php|=).*?\?>|%1$sliteral%2$s.*?%1$s\/literal%2$s/s',
			preg_quote($this->delimiter_start), preg_quote($this->delimiter_end));

		// Remove literal blocks, PHP blocks and comments, to avoid interference with block parsing
		$source = preg_replace_callback($pattern, function ($match) use (&$literals) {
			$nb = count($literals);
			$literals[$nb] = $match[0];
			$lines = substr_count($match[0], "\n");
			return '<?php /*#' . $nb . '#' . str_repeat("\n", $lines) . '#*/?>';
		}, $source);

		// Create block matching pattern
		$anti = preg_quote($this->delimiter_start . $this->delimiter_end, '#');
		$pattern = '#' . preg_quote($this->delimiter_start, '#') . '((?:[^' . $anti . ']|(?R))*?)' . preg_quote($this->delimiter_end, '#') . '#i';

		$blocks = preg_split($pattern, $source, 0, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_DELIM_CAPTURE);

		unset($anti, $pattern);

		$compiled = '';
		$prev_pos = 0;
		$line = 1;

		foreach ($blocks as $i=>$block)
		{
			$pos = $block[1];
			$line += $pos && $pos < strlen($source) ? substr_count($source, "\n", $prev_pos, $pos - $prev_pos) : 0;
			$prev_pos = $pos;

			$block = $block[0];
			$tblock = trim($block);

			if ($i % 2 == 0)
			{
				$compiled .= $block;
				continue;
			}

			// Avoid matching JS blocks and others
			if ($tblock == 'ldelim')
			{
				$compiled .= $this->delimiter_start;
			}
			elseif ($tblock == 'rdelim')
			{
				$compiled .= $this->delimiter_end;
			}
			// Closing blocks
			elseif ($tblock[0] == '/')
			{
				$compiled .= $this->parseClosing($line, $tblock);
			}
			// Variables and strings
			elseif ($tblock[0] == '$' || $tblock[0] == '"' || $tblock[0] == "'")
			{
				$compiled .= $this->parseVariable($line, $tblock);
			}
			elseif ($code = $this->parseBlock($line, $tblock))
			{
				$compiled .= $code;
			}
			else
			{
				// Literal javascript / unknown block
				$compiled .= $this->delimiter_start . $block . $this->delimiter_end;
			}
		}

		unset($source, $i, $block, $tblock, $pos, $prev_pos, $line);

		// Include removed literals, PHP blocks etc.
		foreach ($literals as $i=>$literal)
		{
			// Not PHP code: specific treatment
			if ($literal[0] != '<')
			{
				// Comments
				if (strpos($literal, $this->delimiter_start . '*') === 0)
				{
					// Remove
					$literal = '';
				}
				// literals
				else
				{
					$start_tag = $this->delimiter_start . 'literal' . $this->delimiter_end;
					$end_tag = $this->delimiter_start . '/literal' . $this->delimiter_end;
					$literal = substr($literal, strlen($start_tag), -(strlen($end_tag)));
					unset($start_tag, $end_tag);
				}
			}
			else
			{
				// PHP code, leave as is
			}

			// We need to match the number of lines in literals
			$lines = substr_count($literal, "\n");
			$match = sprintf('<?php /*#%d#%s#*/?>', $i, str_repeat("\n", $lines));

			// replace strings
			$pos = strpos($compiled, $match);
			if ($pos !== false) {
				$compiled = substr_replace($compiled, $literal, $pos, strlen($match));
			}
		}

		return $compiled;
	}

	/**
	 * Parse smarty blocks and functions and returns PHP code
	 */
	protected function parseBlock($line, $block)
	{
		// This is not a valid Smarty block, just assume it is PHP and reject any problem on the user
		if (!preg_match('/^(else if|.*?)(?:\s+(.+?))?$/s', $block, $match))
		{
			return '<?php ' . $block . '; ?>';
		}

		$name = trim(strtolower($match[1]));
		$raw_args = !empty($match[2]) ? trim($match[2]) : '';
		$code = '';

		unset($match);

		// alias
		if ($name == 'else if')
		{
			$name = 'elseif';
		}

		// Start counter
		if ($name == 'foreach' || $name == 'for' || $name == 'while')
		{
			$code = '$_i[] = 0; ';
		}

		// This is just PHP, this is easy
		if (substr($raw_args, 0, 1) == '(' && substr($raw_args, -1) == ')')
		{
			$raw_args = $this->parseMagicVariables($raw_args);

			// Make sure the arguments for if/elseif are wrapped in parenthesis
			// as it could be a false positive
			// eg. "if ($a == 1) || ($b == 1)" would create an error
			// this is not valid for other blocks though (foreach/for/while)

			if ($name == 'if' || $name == 'elseif')
			{
				$code .= sprintf('%s (%s):', $name, $raw_args);
			}
			else
			{
				$code .= sprintf('%s %s:', $name, $raw_args);
			}
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
				$this->parseError($line, 'Invalid block {' . $name . '}: no arguments supplied');
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
			$args = $this->parseArguments($raw_args, $line);

			$args['key'] = isset($args['key']) ? $this->getValueFromArgument($args['key']) : null;
			$args['item'] = isset($args['item']) ? $this->getValueFromArgument($args['item']) : null;
			$args['from'] = isset($args['from']) ? $this->getValueFromArgument($args['from']) : null;

			if (empty($args['item']))
			{
				$this->parseError($line, 'Invalid foreach call: item parameter required.');
			}

			if (empty($args['from']))
			{
				$this->parseError($line, 'Invalid foreach call: from parameter required.');
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
			$args = $this->parseArguments($raw_args, $line);

			if (empty($args['file']))
			{
				$this->parseError($line, '{include} function requires file parameter.');
			}

			if (substr($this->getValueFromArgument($args['file']), 0, 2) == './') {
				$root = dirname($this->template_path);
				$args['file'] = var_export($root . substr($this->getValueFromArgument($args['file']), 1), true);
			}

			$file = $this->exportArgument($args['file']);
			unset($args['file']);

			if (count($args) > 0)
			{
				$assign = '$_s->assign(array_merge(get_defined_vars(), ' . $this->exportArguments($args) . '));';
			}
			else
			{
				$assign = '$_s->assign(get_defined_vars());';
			}

			$code = '$_s = get_class($this); $_s = new $_s(' . $file . ', $this); ' . $assign . ' $_s->display(); unset($_s);';
		}
		else
		{
			if (array_key_exists($name, $this->blocks))
			{
				$args = $this->parseArguments($raw_args);
				$code = sprintf('$_blocks[] = [%s, %s]; echo $this->blocks[%1$s](%2$s, null, $this); ob_start();',
					var_export($name, true), $this->exportArguments($args));
			}
			elseif (array_key_exists($name, $this->functions))
			{
				$args = $this->parseArguments($raw_args);
				$code = 'echo $this->functions[' . var_export($name, true) . '](' . $this->exportArguments($args) . ', $this);';
			}
			else
			{
				// Let's try the user-defined compile callbacks
				// and if none of them return something, we are out

				foreach ($this->compile_functions as $function)
				{
					$code = call_user_func_array($function, [&$this, $line, $block, $name, $raw_args]);

					if ($code)
					{
						break;
					}
				}

				if (!$code)
				{
					if ($this->error_on_invalid_block)
					{
						$this->parseError($line, 'Unknown function or block: ' . $name);
					}
					else
					{
						// Return raw source block, this is probably javascript
						return false;
					}
				}
			}
		}

		if ($name == 'foreach' || $name == 'for' || $name == 'while')
		{
			// Iteration counter
			$code .= ' $iteration =& $_i[count($_i)-1]; $iteration++;';
		}

		$code = '<?php ' . $code . ' //#' . $line . '?>';

		unset($args, $name, $line, $raw_args, $args, $block, $file);

		return $code;
	}

	/**
	 * Parse closing blocks and returns PHP code
	 */
	protected function parseClosing($line, $block)
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

				$code .= ' array_pop($_i); unset($iteration);';
			case 'if':
				$code = 'end' . $name . ';' . $code;
				break;
			default:
			{
				if (array_key_exists($name, $this->blocks))
				{
					$code = '$_b_content = ob_get_contents(); ob_end_clean(); $_b = array_pop($_blocks); echo $this->blocks[$_b[0]]($_b[1], $_b_content, $this); unset($_b, $_b_content);';
				}
				elseif (array_key_exists($name, $this->compile_functions))
				{
					$code = call_user_func_array($this->compile_functions[$name], [&$this, $line, $block, $name]);
				}
				else
				{
					$this->parseError($line, 'Unknown closing block: ' . $name);
				}
				break;
			}
		}

		$code = '<?php ' . $code . ' //#' . $line . '?>';

		unset($name, $line, $block);

		return $code;
	}

	/**
	 * Parse a Smarty variable and returns a PHP code
	 */
	protected function parseVariable($line, $block)
	{
		$code = 'echo ' . $this->parseSingleVariable($block, $line) . ';';
		$code = '<?php ' . $code . ' //#' . $line . '?>';

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
			$out = '$this->_magicVar(' . $match[2] . ', ' . var_export(array_slice($find, 1), true) . ')' . ($match[1] ? '' : @$match[4]);

			// You cannot isset a method, but the method returns NULL if nothing was found,
			// so we can test against this
			if (!empty($match[1])) {
				$out = 'null !== ' . $out;
			}

			return $out;
		}, $str);
	}

	/**
	 * Throws an exception for the current template and hopefully giving the right line
	 * @param  integer $line Source line
	 * @param  string $message  Error message
	 * @param  \Exception $previous Previous exception for the stack
	 * @throws Smartyer_Exception
	 */
	public function parseError($line, $message, $previous = null)
	{
		throw new Smartyer_Exception($message, $this->template_path, $line, $previous);
	}

	/**
	 * Parse block arguments, this is similar to parsing HTML arguments
	 * @param  string $str List of arguments
	 * @param  integer $line Source code line
	 * @return array
	 */
	public function parseArguments(string $str, $line = null): array
	{
		if ($str === '') {
			return [];
		}

		$args = [];
		$state = 0;
		$name = null;
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
					$this->parseError($line, 'Expecting \'=\' after \'' . $last_value . '\'');
				}
			}
			elseif ($state == 2)
			{
				if ($value == '=')
				{
					$this->parseError($line, 'Unexpected \'=\' after \'' . $last_value . '\'');
				}

				$args[$name] = $this->parseSingleVariable($value, $line, false);
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
	public function getValueFromArgument(string $arg): string
	{
		if ($arg[0] == '"' || $arg[0] == "'")
		{
			return str_replace(['\\"', "\\'"], ['"', "'"], substr($arg, 1, -1));
		}

		return $arg;
	}

	/**
	 * Parse a variable, either from a {$block} or from an argument: {block arg=$bla|rot13}
	 * @param  string  $str     Variable string
	 * @param  integer $line    Line position in the source
	 * @param  boolean $escape  Auto-escape the variable output?
	 * @return string 			PHP code to return the variable
	 */
	public function parseSingleVariable($str, $line = null, $escape = true)
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
				$this->parseError($line, 'Unknown modifier name: ' . $mod_name);
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
	public function exportArgument($str)
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
	public function exportArguments(array $args)
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
		$i = 0;

		while ($key = array_shift($keys))
		{
			if ($i++ > 20)
			{
				// Limit the amount of recusivity we can go through
				return null;
			}

			if (is_object($var))
			{
				// Test for constants
				if (defined(get_class($var) . '::' . $key))
				{
					return constant(get_class($var) . '::' . $key);
				}

				if (!isset($var->$key))
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
		if ($type == 'json')
		{
			$str = json_encode($str);
		}

		if (is_array($str) || (is_object($str) && !method_exists($str, '__toString')))
		{
			throw new \InvalidArgumentException('Invalid parameter type for "escape" modifier: ' . gettype($str));
		}

		$str = (string) $str;

		switch ($type)
		{
			case 'html':
			case null:
				return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
			case 'xml':
				return htmlspecialchars($str, ENT_QUOTES | ENT_XML1, 'UTF-8');
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
					return '&#' . ord($match[0]) . ';';
				}, $str);
			case 'mail':
				return str_replace('.', '[dot]', $str);
			case 'json':
				return $str;
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
	static public function replace($str, $a, $b)
	{
		return str_replace($a, $b, $str);
	}

	static public function replaceRegExp($str, $a, $b)
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
	static public function truncate($str, $length = 80, $placeholder = '…', $strict_cut = false)
	{
		// Don't try to use unicode if the string is not valid UTF-8
		$u = preg_match('//u', $str) ? 'u' : '';

		// Shorter than $length + 1
		if (!preg_match('/^.{' . ((int)$length + 1) . '}/s' . $u, $str))
		{
			return $str;
		}

		// Cut at 80 characters
		$str = preg_replace('/^(.{0,' . (int)$length . '}).*$/s' . $u, '$1', $str);

		if (!$strict_cut)
		{
			$cut = preg_replace('/[^\s.,:;!?]*?$/s' . $u, '', $str);

			if (trim($cut) == '') {
				$cut = $str;
			}
		}

		return trim($str) . $placeholder;
	}

	/**
	 * Simple strftime wrapper
	 * @example |date_format:"%F %Y"
	 */
	static public function dateFormat($date, $format = '%b, %e %Y')
	{
		if (is_object($date))
		{
			$date = $date->getTimestamp();
		}
		elseif (!is_numeric($date))
		{
			$date = strtotime($date);
		}

		if (strpos('DATE_', $format) === 0 && defined($format))
		{
			return date(constant($format), $date);
		}

		return @strftime($format, $date);
	}

	/**
	 * Concatenate strings (use |args instead!)
	 * @return string
	 * @example $var|cat:$b:"ok"
	 */
	static public function concatenate()
	{
		return implode('', func_get_args());
	}

	/**
	 * {assign} template function
	 * @param  array  $args
	 * @param  object &$tpl Smartyer object
	 * @return string
	 */
	static public function templateAssign(array $args, &$tpl)
	{
		// Value can be NULL!
		if (!isset($args['var']) || !array_key_exists('value', $args))
		{
			throw new \BadFunctionCallException('Missing argument "var" or "value" to function {assign}');
		}

		$tpl->assign($args['var'], $args['value']);

		if ($tpl->parent) {
			$tpl->parent::templateAssign($args, $tpl->parent);
		}

		return '';
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
		$this->line = (int) $line;
	}
}
