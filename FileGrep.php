<?php 

class FileGrep {
	protected static $grep_path;
	
	protected $paths = array();
	protected $recurse = true;
	protected $pattern_type = 0;
	protected $pattern = null;
	protected $context_size = 0;
	protected $_results;
	
	const PATTERN_TYPE_BASIC = 0;
	const PATTERN_TYPE_EXTENDED = 1;
	const PATTERN_TYPE_PERL = 2;
	const PATTERN_TYPE_FIXEDSTRINGS = 3;
	
	
	/**
	 * Static initializer for easy chaining. Takes an optional search path
	 *
	 * @param string $path 
	 * @return FileGrep
	 */
	static function Init($path=null) {
		return new self($path);
	}
	
	/**
	 * Object constructor. Takes an optional search path
	 *
	 * @param string $path 
	 */
	function __construct($path=null) {
		if ($path) $this->addPath($path);
	}
	
	/**
	 * Add a search path
	 *
	 * @param string $path 
	 * @return FileGrep Returns $this if value is defined, returns value otherwise.
	 */
	function addPath($path) {
		$this->paths[] = $path;
		return $this;
	}
	
	/**
	 * Gets/Sets the recursive flag for searching within folders.
	 *
	 * @param string $value Optional, new value to set.
	 * @return boolean|FileGrep Returns $this if value is defined, returns value otherwise.
	 */
	function recursive($value=null) {
		if (is_null($value)) return $this->recurse;
		
		$this->recurse = (bool)$value;
		return $this;
	}
	
	/**
	 * Gets/Sets the type of search pattern.  See the PATTERN_TYPE_* constants for accepted values.
	 *
	 * @param string $value Optional, new value to set.
	 * @return integer|FileGrep Returns $this if value is defined, returns value otherwise.
	 */
	function patternType($value = null) {
		if (is_null($value)) return $this->pattern_type;
		
		$this->pattern_type = $value;
		return $this;
	}
	
	/**
	 * Gets/Sets the search pattern.  See grep manual entry for pattern formats.
	 *
	 * @param string $value Optional, new pattern to use
	 * @param int $type Optional, the pattern type (see #patternType)
	 * @return string|FileGrep Returns $this if value is defined, returns value otherwise.
	 */
	function pattern($value=null, $type=null) {
		if (is_null($value)) return $this->pattern;
		
		$this->pattern = $value;
		if (!is_null($type)) $this->patternType($type);
		return $this;
	}
	
	/**
	 * Gets/Sets the size of the result context, as lines before and after the search term. See grep manual entry for more info.
	 * If no value is defined, function returns the current setting.  If only a single argument is passed, context is set as both before and after
	 * If both arguments are passed, values define the before and after sizes.
	 * Default context is 0.
	 *
	 * @param int $before
	 * @param int $after 
	 * @return integer|array|FileGrep Returns $this if value is defined, returns value otherwise.
	 */
	function context($before=null, $after=null) {
		if (is_null($before)) return $this->context_size;
		
		if (is_null($after)) {
			$this->context_size = (int)$before;
		} else {
			$this->context_size = array((int)$before, (int)$after);
		}
		return $this;
	}
	

	/**
	 * Executes the search.  Returns the search results as an array.
	 *
	 * @return array
	 */
	function run() {
		if (!$this->pattern) throw new InvalidArgumentException('FileGrep: No search pattern was defined');
		if (empty($this->paths)) throw new InvalidArgumentException("FileGrep: No search paths were defined");
		
		$command = $this->buildCommand();
		
		//echo $command;
		
		$output = array();
		$error = 0;
		$message = exec($command, $output, $error);
		
		switch ($error) {
		case 0:
			break; //results were found, continue as normal
		case 1:
			return $this->_results = array(); //no results were found, return empty dataset
		case 2:
			throw new RuntimeException("FileGrep: Grep returned an error - $message");
		}
		
		
		$results = array();
		$last_r = null;
		$last_path = '';
		$last_line = 0;
		foreach ($output as $result) {
			//if context is larger than 0/0, results are delimited by a line containing only "--".
			//use this to identify the end of a result
			if ($result == '--') {
				$last_r = null;
				$last_path = '';
				$last_line = 0;
				continue;
			}
			
			//file name and context are delimited by a null character, thanks to the --null that we pass to grep
			//split on the null and process the values
			$split = explode("\0", $result);
			$path = $split[0];
			
			
			if (!$path) continue; //no path indicates an empty line. This shouldn't happen, but just in case....
			
			
			//The line number is delimited from the context by either a colon or a hyphen. 
			//Colon indicates the line matches the pattern, hyphen indicates it does not.
			$term_found = true;
			$divi = strpos($split[1], ':');
			$divj = strpos($split[1], '-');
			if ($divi===false || ($divj!==false && $divj < $divi)) {
				$divi = $divj;
				$term_found = false;
			}
			
			
			$line = substr($split[1], 0, $divi);
			$context = substr($split[1], $divi+1);
			
			if ($last_r && $last_r->filepath === $last_path && $line == $last_line+1) {
				//if the previous row reference the same file, and the line just before this one, this line is further context to the previous
				
				$last_r->contexts[] = $context;
				$last_r->length++;
				$last_r->context_end = $line;
				if ($term_found) $last_r->lines[] = $line; //only store a line number if it contains the search term.
				
			} else {
				
				$last_path = $path;
				
				$last_r = new FileGrepResult();
				$last_r->filepath = $path;
				$last_r->length = 1;
				$last_r->contexts[] = $context;
				$last_r->context_start = $line;
				$last_r->context_end = $line;
				if ($term_found) $last_r->lines[] = $line; //only store a line number if it contains the search term.

				$results[] = $last_r;
			}
			$last_line = $line;
			
		}
		
		
		return $this->_results = $results;
		
	}
	
	
	/**
	 * Returns the results found by the last run, or null if search has not yet been performed.
	 *
	 * @return array|null
	 */
	function results() {
		return $this->_results;
	}
	
	
	/**
	 * Internal function that builds the grep command.
	 *
	 * @return string
	 * @access protected
	 */
	protected function buildCommand() {
		$command = array();
		
		$grep_path = self::FindGrep();
		if (!$grep_path) throw Exception("Grep could not be found.");
		
		$command[] = escapeshellarg($grep_path);
		
		$command[] = '--line-number';
		$command[] = '--null';
		
		if (is_array($this->context_size)) {
			$command[] = '--before-context='.$this->context_size[0];
			$command[] = '--after-context='.$this->context_size[1];
		} else {
			$command[] = '--context='.$this->context_size;
		}
		
		if ($this->recurse) 
			$command[] = '--directories=recurse';
		else 
			$command[] = '-directories=read';
		
		switch ($this->pattern_type) {
		case self::PATTERN_TYPE_BASIC:
			$command[] = '--basic-regexp';
			break;
		case self::PATTERN_TYPE_EXTENDED:
			$command[] = '--extended-regexp';
			break;
		case self::PATTERN_TYPE_PERL:
			$command[] = '--perl-regexp';
			break;
		case self::PATTERN_TYPE_FIXEDSTRINGS:
			$command[] = '--fixed-strings';
			break;
		}
		
		$command[] = '--regexp='.escapeshellarg($this->pattern);
		
		foreach ($this->paths as $path) $command[] = escapeshellarg($path);
		
		return implode(' ',$command);
	}
	
	
	/**
	 * Scans through the filesystem trying to locate the grep binary
	 *
	 * @return boolean True if grep was found
	 * @access protected
	 **/
	protected static function FindGrep() {
		if (self::$grep_path) return self::$grep_path;
		
		//if a grep path has been defined, always use that.
		if (defined('GREP_PATH')) {
			self::$grep_path == GREP_PATH;
			return self::$grep_path;
		}
		
		$locations = array(
			'/usr/bin/',
			'/usr/local/bin/',
			'/opt/local/bin/',
			'/opt/bin/',
			'/opt/csw/bin/'
		);
		
		foreach ($locations as $loc) {
			if (is_executable("{$loc}grep")) {
				self::$grep_path = "{$loc}grep";
				return self::$grep_path;
			}			
		}
		
		
		//wasn't found in the usual places.  Try doing a which search.
		$uname = php_uname('s');
		if ((stripos($uname, 'linux') !== FALSE) || (stripos($uname, 'freebsd') !== FALSE) || (stripos($uname, 'aix') !== FALSE)) {
			$nix_search = 'whereis -b grep';
			exec($nix_search, $nix_output);
			$nix_output = trim(str_replace('grep:', '', join("\n", $nix_output)));
			
			if (!$nix_output) {
				return self::$grep_path = false;
			}
		
			self::$grep_path = preg_replace('#^(.*)grep$#i', '\1', $nix_output);
			return self::$grep_path;
			
		} elseif ((stripos($uname, 'darwin') !== FALSE) || (stripos($uname, 'netbsd') !== FALSE) || (stripos($uname, 'openbsd') !== FALSE)) {
			$osx_search = 'whereis grep';
			exec($osx_search, $osx_output);
			$osx_output = trim(join("\n", $osx_output));
			
			if (!$osx_output) {
				return self::$grep_path = false;
			}
		
			if (preg_match('#^(.*)grep#i', $osx_output, $matches)) {
				self::$grep_path = $matches[1];
				return self::$grep_path;
			}
		}
		
		return self::$grep_path = false;
		
	}
}


class FileGrepResult {
	var $filepath;
	var $lines;
	var $length;
	var $contexts;
	var $context_start;
	var $context_end;
	
	function __get($name) {
		switch ($name) {
		case 'line':
			return $lines[0];
			break;
		case 'context':
			return implode(PHP_EOL, $this->contexts);
		}
	}
}


// $o = new FileGrep('/Users/chiper/Library/Application Support/Linkinus 2/Logs/Freenode/Channels/');
// $o->pattern('iPhone|android', FileGrep::PATTERN_TYPE_EXTENDED);
// $o->context(1);
// $o->run();
// print_r($o->results());
