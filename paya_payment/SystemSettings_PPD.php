<?php


/**
 * SystemSettings class
 * @author 
 *
 */
class SystemSettings {
	
	/**
	 * Returns an array of configuration options set in config.ini
	 * 
	 * @author 
	 * @return array
	 */
	public static function get() {
		$config = parse_ini_file('config_PPD.ini');
		return $config;
	}
}

?>