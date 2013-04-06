<?php (defined('BASEPATH')) OR exit('No direct script access allowed');

(defined('EXT')) OR define('EXT', '.php');

global $CFG;

/* get module locations from config settings or use the default module location and offset */
is_array(Modules::$locations = $CFG->item('modules_locations')) OR Modules::$locations = array(
	APPPATH.'modules/' => '../modules/',
);

/* PHP5 spl_autoload */
spl_autoload_register('Modules::autoload');

/**
 * Modular Extensions - HMVC
 *
 * Adapted from the CodeIgniter Core Classes
 * @link	http://codeigniter.com
 *
 * Description:
 * This library provides functions to load and instantiate controllers
 * and module controllers allowing use of modules and the HMVC design pattern.
 *
 * Install this file as application/third_party/MX/Modules.php
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
class Modules
{
	public static $routes, $registry, $locations;
	
	/**
	* Run a module controller method
	* Output from module is buffered and returned.
	**/
	public static function run($module) {
		
		$method = 'index';
		
		if(($pos = strrpos($module, '/')) != FALSE) {
			$method = substr($module, $pos + 1);		
			$module = substr($module, 0, $pos);
		}

		if($class = self::load($module)) {
			
			if (method_exists($class, $method))	{
				ob_start();
				$args = func_get_args();
				$output = call_user_func_array(array($class, $method), array_slice($args, 1));
				$buffer = ob_get_clean();
				return ($output !== NULL) ? $output : $buffer;
			}
		}
		
		log_message('error', "Module controller failed to run: {$module}/{$method}");
	}
	
	/** Load a module controller **/
	public static function load($module) {

		(is_array($module)) ? list($module, $params) = each($module) : $params = NULL;	
		
		/* get the requested controller class name */
		$alias = strtolower(basename($module));

		/* create or return an existing controller from the registry */
		if ( ! isset(self::$registry[$alias])) {
			
			/* find the controller */
			list($class) = CI::$APP->router->locate(explode('/', $module));
	
			/* controller cannot be located */
			if (empty($class)) return;
	
			/* set the module directory */
			$path = APPPATH.'controllers/'.CI::$APP->router->fetch_directory();
			
			/* load the controller class */
			$class = $class.CI::$APP->config->item('controller_suffix');
			self::load_file($class, $path);
			
			/* create and register the new controller */
			$controller = ucfirst($class);	
			self::$registry[$alias] = new $controller($params);
		}
		
		return self::$registry[$alias];
	}
	
	/** Library base class autoload **/
	public static function autoload($class) {
		
		/* don't autoload CI_ prefixed classes or those using the config subclass_prefix */
		if (strstr($class, 'CI_') OR strstr($class, config_item('subclass_prefix'))) return;

		/* autoload Modular Extensions MX core classes */
		if (strstr($class, 'MX_') AND is_file($location = dirname(__FILE__).'/'.substr($class, 3).EXT)) {
			include_once $location;
			return;
		}
		
		/* autoload core classes */
		if(is_file($location = APPPATH.'core/'.$class.EXT)) {
			include_once $location;
			return;
		}		
		
		/* autoload library classes */
		if(is_file($location = APPPATH.'libraries/'.$class.EXT)) {
			include_once $location;
			return;
		}		
	}

	/** Load a module file **/
	public static function load_file($file, $path, $type = 'other', $result = TRUE)	{
		
		$file = str_replace(EXT, '', $file);		
		$location = $path.$file.EXT;
		
		if ($type === 'other') {			
			if (class_exists($file, FALSE))	{
				log_message('debug', "**File already loaded: {$location}");				
				return $result;
			}	
			include_once $location;
		} else { 
		
			/* load config or language array */
			include $location;

			if ( ! isset($$type) OR ! is_array($$type))				
				show_error("{$location} does not contain a valid {$type} array");

			$result = $$type;
		}
		log_message('debug', "File loaded: {$location}");
		return $result;
	}

	/**
	 * Find a file
	 * Scans for files located within modules directories.
	 * Also scans application directories for models, plugins and views.
	 * Returns the first result found, not all results
	 * 
	 * @param string $file
	 * @param string $module
	 * @param string $base
	 * @param string $location
	 * @return array Returns the location, filename, and TRUE if it is an extension to a helper
	 */
	public static function find($file, $module, $base, $location = '') {
			
		$segments = explode('/', $file);

		$file = array_pop($segments);
		$file_ext = (pathinfo($file, PATHINFO_EXTENSION)) ? $file : $file.EXT;
		
		$path = ltrim(implode('/', $segments).'/', '/');
		$modules = array();
		
		// We want to execute the below first under the assumption that the $file contains
		// the module as well in the segment, and if so is assumed to be most likely the
		// correct location, and this limits how much searching we do as if found it
		// immediately returns that result instead of searching through both
		if ( ! empty($segments)) {
			$modules[array_shift($segments)] = ltrim(implode('/', $segments).'/','/');			
		}
		
		// If $module arg exists, then add as a place to search with $file as full path
		// as it would then be assumed to have no module in that first arg then
		if ($module && !isset($modules[$module])) {
			$modules[$module] = $path;
		}
		//One last area to search if modules is still empty, use file as the module name
		elseif(empty($modules)) {
			$modules[$file] = $path;
		}
		
		if($location) {
			foreach($modules as $module => $subpath) {
				$fullpath = $location.$module.'/'.$base.$subpath;
					
				if ($base == 'libraries/' AND is_file($fullpath.ucfirst($file_ext)))
					return array($fullpath, ucfirst($file), FALSE);
				
				// Is there a possible helper extension here?
				if($base == 'helpers/' && is_file($fullpath.config_item('subclass_prefix').$file_ext)) {
					return array($fullpath, $file, TRUE);								
				}

				//log_message('debug', "Checking to see if $fullpath$file_ext exists: ". ((is_file($fullpath.$file_ext)) ? '**yes**' : 'no' ));
				if (is_file($fullpath.$file_ext)) return array($fullpath, $file, FALSE);
			}	
		}
		else {
			//Go through loop if necessary
			foreach($modules as $module => $subpath) {
				$directories = CI::$APP->router->module_map($module);
				
				//If no directories are returned for the module above, then search all
				if(empty($directories)) {
					$directories = CI::$APP->router->module_map();
				}
				
				foreach($directories as $module => $locations) {
					foreach($locations as $location) {
						$fullpath = $location.$module.'/'.$base.$subpath;
					
						if ($base == 'libraries/' AND is_file($fullpath.ucfirst($file_ext))) {
							return array($fullpath, ucfirst($file), FALSE);
						}
						
						// Is there a possible helper extension here?
						if($base == 'helpers/' && is_file($fullpath.config_item('subclass_prefix').$file_ext)) {
							return array($fullpath, $file, TRUE);								
						}
						
						//log_message('debug', "Checking to see if $fullpath$file_ext exists: ". ((is_file($fullpath.$file_ext)) ? '**yes**' : 'no' ));
						if (is_file($fullpath.$file_ext)) return array($fullpath, $file, FALSE);
					}
				}
			}
		}
		
		return array(FALSE, $file, FALSE);	
	}
	
	/** Parse module routes **/
	public static function parse_routes($module, $uri, $locations = array()) {
	
		/* load the route file and merge routes if more than one location is read */
		if ( ! isset(self::$routes[$module])) {
			self::$routes[$module] = array();
						
			if(empty($locations)) {
				if (list($path) = self::find('routes', $module, 'config/') AND $path) {
					self::$routes[$module] = self::load_file('routes', $path, 'route');
				}
			}
			else {
				foreach($locations as $module => $locations) {
					foreach($locations as $location) {
						if (list($path) = self::find('routes', $module, 'config/', $location) AND $path) {
							self::$routes[$module] = array_merge(self::$routes[$module], self::load_file('routes', $path, 'route'));
						}
					}
				}
			}
		}
		
		if ( ! isset(self::$routes[$module])) return;
			
		/* parse module routes */
		foreach (self::$routes[$module] as $key => $val) {
			//log_message('debug', "-- Found route rule: $key -> " . (($val) ? $val : '[Removed this default route]'));
					
			$key = str_replace(array(':any', ':num'), array('.+', '[0-9]+'), $key);
			
			if (preg_match('#^'.$key.'$#', $uri)) {							
				if (strpos($val, '$') !== FALSE AND strpos($key, '(') !== FALSE) {
					$val = preg_replace('#^'.$key.'$#', $val, $uri);
				}

				if(array_shift(explode("/", $val))) {
					log_message('debug', "**** Found matching route '$uri' in module '$module', controller '" . array_shift(explode("/", $val)) . "', method '" . array_pop(explode("/", $val)) . "'");
				}
				return explode('/', $module.'/'.$val);
			}
		}
	}
}