<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2013 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */

// -- Environment setup --------------------------------------------------------

// Load the core Kohana class
require SYSPATH . "classes/Kohana/Core.php";

if (is_file(APPPATH . "classes/Kohana.php")) {
  // Application extends the core
  require APPPATH . "classes/Kohana.php";
} else {
  // Load empty core extension
  require SYSPATH . "classes/Kohana.php";
}

// Kohana default bootstrap normally sets the default timezone and locale
// here, but we take care of that in the gallery module.

/**
 * Enable the Kohana auto-loader.
 *
 * @link http://kohanaframework.org/guide/using.autoloading
 * @link http://www.php.net/manual/function.spl-autoload-register
 */
spl_autoload_register(array("Kohana", "auto_load"));

/**
 * Enable the Kohana auto-loader for unserialization.
 *
 * @link http://www.php.net/manual/function.spl-autoload-call
 * @link http://www.php.net/manual/var.configuration#unserialize-callback-func
 */
ini_set("unserialize_callback_func", "spl_autoload_call");

// -- Configuration and initialization -----------------------------------------

/**
 * Set the default language
 */
I18n::lang('en-us');

/**
 * Set Kohana::$environment if a 'KOHANA_ENV' environment variable has been supplied.
 *
 * Note: If you supply an invalid environment name, a PHP warning will be thrown
 * saying "Couldn't find constant Kohana::<INVALID_ENV_NAME>"
 */
if (isset($_SERVER["KOHANA_ENV"])) {
  Kohana::$environment = constant("Kohana::" . strtoupper($_SERVER["KOHANA_ENV"]));
}

/**
 * Initialize Kohana, setting the default options.
 *
 * The following options are available:
 *
 * - string   base_url    path, and optionally domain, of your application   NULL
 * - string   index_file  name of your index file, usually "index.php"       index.php
 * - string   charset     internal character set used for input and output   utf-8
 * - string   cache_dir   set the internal cache directory                   APPPATH/cache
 * - integer  cache_life  lifetime, in seconds, of items cached              60
 * - boolean  errors      enable or disable error handling                   TRUE
 * - boolean  profile     enable or disable internal profiling               TRUE
 * - boolean  caching     enable or disable internal caching                 FALSE
 * - boolean  expose      set the X-Powered-By header                        FALSE
 */
Kohana::init(
  array(
    /**
     * Base path of the web site. If this includes a domain, eg: localhost/kohana/
     * then a full URL will be used, eg: http://localhost/kohana/. If it only includes
     * the path, and a site_protocol is specified, the domain will be auto-detected.
     *
     * Here we do our best to autodetect the base path to Gallery.  If your url is something like:
     *   http://example.com/gallery3/index.php/album73/photo5.jpg?param=value
     *
     * We want the site_domain to be:
     *   /gallery3
     *
     * In the above example, $_SERVER["SCRIPT_NAME"] contains "/gallery3/index.php" so
     * dirname($_SERVER["SCRIPT_NAME"]) is what we need.  Except some low end hosts (namely 1and1.com)
     * break SCRIPT_NAME and it contains the extra path info, so in the above example it'd be:
     *   /gallery3/index.php/album73/photo5.jpg
     *
     * So dirname doesn't work.  So we do a tricky workaround where we look up the SCRIPT_FILENAME (in
     * this case it'd be "index.php" and we delete from that part onwards.  If you work at 1and1 and
     * you're reading this, please fix this bug!
     *
     * Rawurlencode each of the elements to avoid breaking the page layout.
     */
    "base_url" => implode(
      "/", array_map(
        "rawurlencode", explode(
          "/",
          substr($_SERVER["SCRIPT_NAME"], 0,
                 strpos($_SERVER["SCRIPT_NAME"], basename($_SERVER["SCRIPT_FILENAME"])))))),

    "index_file" => "index.php",
    "charset" => "utf-8",
    "cache_dir" => VARPATH . "cache",
    "cache_life" => 60,
    "errors" => true,
    "profiling" => true,
    "caching" => false,
    "expose" => false
));

/**
 * Attach the file write to logging. Multiple writers are supported.
 */
Kohana::$log->attach(new Log_File(VARPATH . "logs"));

/**
 * Attach a file reader to config. Multiple readers are supported.
 */
Kohana::$config->attach(new Config_File);

/**
 * Enable modules. Modules are referenced by a relative or absolute path.
 */
Kohana::modules(
  array_merge(
    (TEST_MODE ?
     array() :
     array(
       "gallery_unit_test" => MODPATH . "gallery_unit_test",
       "unit_test" => MODPATH . "unit_test")),
    array(
      // gallery should be first here so that it can override classes
      // in the other official Kohana modules
      "gallery" => MODPATH . "gallery",
      "database" => MODPATH . "database",
      "orm" => MODPATH . "orm",
      "cache" => MODPATH . "cache"))
);

// If var/database.php doesn't exist, then we assume that the Gallery is not properly installed
// and send users to the installer.
if (!file_exists(VARPATH . "database.php")) {
  url::redirect(url::abs_file("installer"));
}

// Simple and cheap test to make sure that the database config is ok.  Do this before we do
// anything else database related.
try {
  Database::instance()->connect();
} catch (Kohana_PHP_Exception $e) {
  print "Database configuration error.  Please check var/database.php";
  exit;
}

// Override the cookie and user agent if they're provided in the request
isset($_POST["g3sid"]) && $_COOKIE["g3sid"] = $_POST["g3sid"];
isset($_GET["g3sid"]) && $_COOKIE["g3sid"] = $_GET["g3sid"];
isset($_POST["user_agent"]) && $_SERVER["HTTP_USER_AGENT"] = $_POST["user_agent"];
isset($_GET["user_agent"]) && $_SERVER["HTTP_USER_AGENT"] = $_GET["user_agent"];

// Initialize our session support
Session::instance();

// Set the default driver for caching.  Gallery_Cache_Database is the implementation
// that we provide.
Cache::$default = "database";

// Pick a salt for our cookies.
// @todo: should this be something different for each system?  Perhaps something tied
// to the domain?
Cookie::$salt = "g3";

// Initialize I18n support
Gallery_I18n::instance();

Route::set("default", "(<controller>(/<action>))")
  ->defaults(array("controller" => "albums",
                   "action" => "index"));

// Load all active modules.  This will trigger each module to load its own routes.
module::load_modules();

register_shutdown_function(array("gallery", "shutdown"));

// Notify all modules that we're ready to serve
gallery::ready();

