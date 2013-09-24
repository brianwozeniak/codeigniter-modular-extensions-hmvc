<?php (defined('BASEPATH')) OR exit('No direct script access allowed');

/* load the MX core module class */
require dirname(__FILE__).'/Modules.php';

/**
 * Modular Extensions - HMVC
 *
 * Adapted from the CodeIgniter Core Classes
 * @link	http://codeigniter.com
 *
 * Description:
 * This library extends the CodeIgniter router class.
 *
 * Install this file as application/third_party/MX/Router.php
 *
 * @copyright	Copyright (c) 2011 Wiredesignz
 * @version 	5.4
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * 
 * This is a forked version of the original Modular Extensions - HMVC library to
 * support better module routing, and speed optimizations. These additional
 * changes were made by:
 * 
 * @author		Brian Wozeniak
 * @copyright	Copyright (c) 1998-2013, Unmelted, LLC
 **/
class MX_Router extends CI_Router
{
	protected $module;
	protected $location;
	protected $map = array();
	protected $remove_default_routes = TRUE;
	
	public function fetch_module() {
		return $this->module;
	}
	
	public function fetch_location() {
		return $this->location;
	}
	
	/**
	 * A simple function which maps out exactly what path a module is located. It will
	 * also cache the results to avoid extra work and allows a specific module to be
	 * looked up or all modules and their locations returned.
	 *
	 * @param string $module The module to lookup, leave blank to return all
	 * @return array A list of all module paths or filtered to a specified module
	 */
	public function module_map($module = false) {

		// If we have already done this, use the cached results
		if(!empty($this->map)) {

			// If a directory which holds modules is specified, only return those
			if($module) {
				if(!isset($this->map[$module])) {
					return array();
				}
				return array($module => $this->map[$module]);
			}
			
			// Otherwise return the full associative array of modules and their locations
			return $this->map;
		}
		// Since no cached results exist, lets do the work!
		else {
			// Go through each directory that contains modules
			foreach (Modules::$locations as $location => $offset) {
	
				// Get all modules(folders) in the folder that holds modules
				$directories = array();
				if ($fp = @opendir($location)) {
					while (FALSE !== ($file = readdir($fp))) {
						if (trim($file, '.') && $file[0] != '.' && @is_dir($location . $file)) {
							$this->map[$file][] = $location;
						}
					}
				}
			}

			// Everything is cached, now return the results by running func again
			if(!empty($this->map)) {
				return $this->module_map($module);
			}
			
			// No modules? Return empty array
			return array();
		}
	}
	
	public function _validate_request($segments) {
	
		$BM =& load_class('Benchmark', 'core');
		$benchmark = 'uri_routing:_locate_( ' . implode("::", $segments) . ' )';
		$BM->mark($benchmark . '_start');
	
		$result = FALSE;
	
		if (count($segments) == 0) {
			$result = $segments;
		}
		/* locate module controller */
		elseif ($located = $this->locate($segments)) {
			$result = $located;
		}
		/* use a default 404_override controller */
		elseif (isset($this->routes['404_override']) AND $this->routes['404_override']) {
			$segments = explode('/', $this->routes['404_override']);
			if ($located = $this->locate($segments)) {
				$result = $located;
			}
		}

		$BM->mark($benchmark . '_end');
		
		/* no controller found */
		if(!$result) {
			show_404($this->uri->uri_string);
		}	
	
		return $result;	
	}
	
	/**
	 *  Parse Routes
	 *
	 * This function matches any routes that may exist in the config/routes.php file
	 * against the URI to determine if the class/method need to be remapped.
	 * 
	 * It has been extended to be strict with trailing slashes.
	 *
	 * @access	private
	 * @return	void
	 */
	function _parse_routes()
	{
		// Turn the segment array into a URI string
		$uri = implode('/', $this->uri->segments);
		
		// Does our URI actually have a trailing slash?
		if($this->uri->has_trailing_slash()) {
			$uri .= '/';
		}
	
		// Is there a literal match?  If so we're done
		if (isset($this->routes[$uri]))
		{
			return $this->_set_request(explode('/', $this->routes[$uri]));
		}
	
		// Loop through the route array looking for wild-cards
		foreach ($this->routes as $key => $val)
		{
			// Convert wild-cards to RegEx
			$key = str_replace(':any', '.*[^\/]{1}', str_replace(':num', '[0-9]+', $key));
	
			// Does the RegEx match?
			if (preg_match('#^'.$key.'$#', $uri))
			{
				// Do we have a back-reference?
				if (strpos($val, '$') !== FALSE AND strpos($key, '(') !== FALSE)
				{
					$val = preg_replace('#^'.$key.'$#', $val, $uri);
				}
	
				return $this->_set_request(explode('/', $val));
			}
		}
	
		// If we got this far it means we didn't encounter a
		// matching route so we'll set the site default route
		$this->_set_request($this->uri->segments);
	}
	
	/** Locate the controller **/
	public function locate($segments) {

		$this->module = '';
		$this->directory = '';
		$this->location = '';
		$ext = $this->config->item('controller_suffix').EXT;
		$routed = implode("/", $segments);
			
		/* use module route if available and use exact module location if exists */
		if (isset($segments[0]) && $this->module_map($segments[0]) AND list($routes) = Modules::parse_routes($segments[0], $routed, $this->module_map($segments[0]))) {
			$segments = $routes;
		}
		// Otherwise go through all module routes and stop at the first match if any (lower precedence)
		elseif(isset($segments[0])) {
			//log_message('debug', "Scanning for first match in all modules' routing files with the URI segment: " . $routed);
			
			//Get all of the module names and locations
			$directories = $this->module_map();
				
			foreach($directories as $module => $locations) {
				if(list($routes) = Modules::parse_routes($module, $routed, array($module => $locations))) {
					$segments = $routes;

					//Since a route is found, no need to keep looking, break out of loop
					break;
				}
			}
		}
		
		// Let's see where the request originated ie, from a module or router, (is there a better way?)
		// If it comes from a router that assumes it is being loaded via a public website address, if it comes from
		// a module then that means it was called from somewhere internally. Tests indicate this is almost a free
		// task, benchmarks show 0.0000 seconds to complete, so not even traceable.
		$trace = debug_backtrace();
		$module_source = (isset($trace[1]['class']) && $trace[1]['class'] == 'Modules' && isset($trace[1]['function']) && $trace[1]['function'] == 'load');
		
		// Should all modules be unaccessable unless a route explicitly matches?
		if($this->remove_default_routes && !$module_source && empty($routes) && !$this->_validate_route($routed)) {
			return;
		}
		
		// Let's make sure all segments are lower case. It is possible in routing files that uppercase letters are
		// used. This ensures everything gets translated to lowercase. The main reason for doing this is that on
		// Windows machines since files will match any case, it may work there, but on Linux machines it must match
		// the exact case. This could cause differences between development machines and production machines which
		// would allow a route to work fine on Windows, but not on Linux, and may be hard to trace the cause. By
		// adding this you should always ensure all controllers are in lowercase.
		$segments = array_map('strtolower', $segments);
		
		/* get the segments array elements */
		list($module, $directory, $controller) = array_pad($segments, 3, NULL);

		/* check modules */
		$directories = $this->module_map($module);

		foreach ($directories as $module => $locations) {
			foreach($locations as $location) {
				$offset = Modules::$locations[$location];
		
				/* module exists? */
				if (is_dir($source = $location.$module.'/controllers/')) {
					$this->module = $module;
					$this->directory = $offset.$module.'/controllers/';
					$this->location = $location;
					
					/* module sub-controller exists? */
					if($directory AND is_file($source.$directory.$ext)) {
						return array_slice($segments, 1);
					}
			
					/* module sub-directory exists? */
					if($directory AND is_dir($source.$directory.'/')) {
					
						$source = $source.$directory.'/';
						$this->directory .= $directory.'/';
					
						/* module sub-directory controller exists? */
						if(is_file($source.$directory.$ext)) {
							return array_slice($segments, 1);
						}
							
						/* module sub-directory sub-controller exists? */
						if($controller AND is_file($source.$controller.$ext))	{
							return array_slice($segments, 2);
						}
					}
					
					/* module controller exists? */			
					if(is_file($source.$module.$ext)) {
						return $segments;
					}
				}
			}
		}
		
		/* application controller exists? */			
		if (is_file(APPPATH.'controllers/'.$module.$ext)) {
			return $segments;
		}
		
		/* application sub-directory controller exists? */
		if($directory AND is_file(APPPATH.'controllers/'.$module.'/'.$directory.$ext)) {
			$this->directory = $module.'/';
			return array_slice($segments, 1);
		}
		
		/* application sub-directory default controller exists? */
		if (is_file(APPPATH.'controllers/'.$module.'/'.$this->default_controller.$ext)) {
			$this->directory = $module.'/';
			return array($this->default_controller);
		}
	}

	public function set_class($class) {
		$this->class = $class.$this->config->item('controller_suffix');
	}
	
	/**
	 * Simply returns if our URI had a trailing slash or not
	 * 
	 * @return boolean TRUE if URI has trailing slash, FALSE if not
	 */
	protected function has_trailing_slash() {
		return $this->uri->has_trailing_slash();
	}
	
	/**
	 * Takes a translated route, parses wildcards, and returns TRUE if anything matches as being valid
	 * 
	 * @param string $routed
	 */
	private function _validate_route($routed) {
		
		// Break down all the segments of our translated route
		$routed_segments = explode('/', $routed);
		
		// Do we have a match with our default controller?
		if ($this->default_controller == $routed || $this->default_controller == $routed_segments[0]) {
			return TRUE;
		}
				
		// Turn the URI segment array into a URI string
		$uri = implode('/', $this->uri->segments);
		
		// Go through each route and see if it matches with wildcards
		foreach ($this->routes as $key => $val) {
			$key = str_replace(':any', '.+', str_replace(':num', '[0-9]+', $key));
			
			if (preg_match('#^' . $key . '$#', $uri))	{				
				return TRUE;
			}
		}
		
		// Finally do we have a match with a 404 override?
		if(isset($this->routes['404_override']) AND $this->routes['404_override'] == $routed) {
			return TRUE;			
		}
		
		return FALSE;
	}
}