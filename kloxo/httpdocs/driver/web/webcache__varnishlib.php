<?php

include_once("webcache__lib.php");

class webcache__varnish extends webcache__
{
	function __construct()
	{
		parent::__construct();
	}

	static function uninstallMe()
	{
		parent::uninstallMeTrue('varnish');
	}

	static function installMe()
	{
		parent::installMeTrue('varnish');
		self::copyConfigMe();
	}

	static function copyConfigMe()
	{
		$nolog = null;

		$pathsrc = "/usr/local/lxlabs/kloxo/file/varnish";
		$pathdrv = "/home/varnish";
		$pathetc = "/etc";

		log_cleanup("Copy all contents of 'varnish'", $nolog);

		log_cleanup("- Copy {$pathsrc} to {$pathdrv}", $nolog);
		exec("cp -rf {$pathsrc} /home");

		if (!file_exists("/etc/varnish")) { return; }

		$t = getLinkCustomfile($pathdrv . "/etc/conf", "default.vcl");
		lxfile_cp($t, "$pathetc/varnish/default.vcl");

		$t = getLinkCustomfile($pathdrv . "/etc/sysconfig", "varnish");
		lxfile_cp($t, "$pathetc/sysconfig/varnish");
	}
}