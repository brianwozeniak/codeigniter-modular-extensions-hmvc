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
 * @copyright	Copyright (c) 1998-2012, Unmelted, LLC
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
		}
	}
	
	public function _validate_request($segments) {

		if (count($segments) == 0) return $segments;
		
		/* locate module controller */
		if ($located = $this->locate($segments)) return $located;
		
		/* use a default 404_override controller */
		if (isset($this->routes['404_override']) AND $this->routes['404_override']) {
			$segments = explode('/', $this->routes['404_override']);
			if ($located = $this->locate($segments)) return $located;
		}
		
		/* no controller found */
		show_404($this->uri->uri_string);
	}
	
	/** Locate the controller **/
	public function locate($segments) {		
		
		$this->module = '';
		$this->directory = '';
		$this->location = '';
		$routes = '';
		$ext = $this->config->item('controller_suffix').EXT;
		$uri = implode("/", $segments);
		
		/* use module route if available and use exact module location if exists */
		if (isset($segments[0]) && $this->module_map($segments[0]) AND $routes = Modules::parse_routes($segments[0], $uri, $this->module_map($segments[0]))) {
			$segments = $routes;
		}
		// Otherwise go through all module routes and stop at the first match if any (lower precedence)
		elseif(isset($segments[0])) {
			//log_message('debug', "Scanning for first match in all modules' routing files with the URI segment: " . $uri);
			
			//Get all of the module names and locations
			$directories = $this->module_map();
				
			foreach($directories as $module => $locations) {
				if($routes = Modules::parse_routes($module, $uri, array($module => $locations))) {
					$segments = $routes;

					//Since a route is found, no need to keep looking, break out of loop
					break;
				}
			}
		}

		// Should all modules be unaccessable unless a route explicitly matches?
		if($this->remove_default_routes && empty($routes) && $this->routes['default_controller'] != $uri && $this->routes['404_override'] != $uri) {
			return;
		}

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
}