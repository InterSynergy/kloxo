<?php

// MR -- to make sure no yum running in background
system("rm -f /var/run/yum.pid");

// system("yum-complete-transaction");
 
$lxlabspath = "/usr/local/lxlabs";
$kloxopath = "{$lxlabspath}/kloxo";
$currentpath = realpath(dirname(__FILE__));

date_default_timezone_set('UTC');
$currentstamp = date("Y-m-d-H-i-s");

// State must declate first
$kloxostate = getKloxoType();

$opt = parse_opt($argv);

$installtype = (isset($opt['install-type'])) ? $opt['install-type'] : 'master';
$installfrom = (isset($opt['install-from'])) ? $opt['install-from'] : 'install';
$installstep = (isset($opt['install-step'])) ? $opt['install-step'] : '1';

$mypass = password_gen();

$dbroot = "root";
// MR -- always set to ''
$dbpass = '';

$osversion = find_os_version();

function lxins_main()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

//	$arch = trim( `arch` );
//	$arch = php_uname('m');

	// MR -- to make sure /tmp ready for all; found mysql not start not 1777
	system("chmod 1777 /tmp");

	// MR -- modified sysctl.conf because using socket instead port for php-fpm
	$pattern = "fs.file-max";
	$sysctlconf = file_get_contents("/etc/sysctl.conf");

	// MR - https://bbs.archlinux.org/viewtopic.php?pid=1002264
	// also add 'fs.aio-max-nr' for mysql 5.5 innodb aio issue
	$patch = "\n### begin -- add by Kloxo-MR\n" .
		"fs.aio-max-nr = 1048576\n" .
		"fs.file-max = 1048576\n" .
		"vm.swappiness = 10\n" .
		"vm.vfs_cache_pressure = 50\n" .
		"vm.dirty_background_ratio = 15\n" .
		"vm.dirty_ratio = 5\n" .
		"### end -- add by Kloxo-MR\n";

	// MR -- TODO: also patch 'alias verynice="ionice -c3 nice -n 15"' to '~/.bashrc'

	if (strpos($sysctlconf, $pattern) !== false) {
		//
	} else {
		system("echo '{$patch}' >> /etc/sysctl.conf; sysctl -e -p");
	}

	if ($installstep === '2') {
		kloxo_install_step2();

		if ($installtype === 'master') {
			if (file_exists("/var/lib/mysql/kloxo")) {
				kloxo_install_bye();
			}
		}

		exit;
	}

	// MR -- no needed because using mratwork-release.rpm
//	install_yum_repo();

	// MR -- also issue on Centos 5.9 - prevent for update!
	if (php_uname('m') === 'x86_64') {
		if (isRpmInstalled('mysql.i386')) {
			system("yum remove mysql*.i386 -y");
		}

		if (isRpmInstalled('mysql.i686')) {
			system("yum remove mysql*.i686 -y");
		}
	}

	if ($kloxostate !== 'none') {
		//--- Create temporary flags for install
		system("mkdir -p /var/cache/kloxo/");

		if ($installfrom !== 'setup') {
			//--- Ask Reinstall
			if (get_yes_no("\nKloxo seems already installed do you wish to continue?") == 'n') {
				print("Installation Aborted.\n");

				exit;
			}
		}

		system("cp -rf {$kloxopath} {$kloxopath}.{$currentstamp}");
	} else {
		// MR -- issue found on Centos 5.9 where have 'default' iptables config
		$iptp = '/etc/sysconfig';
		$ipts = array('iptables', 'ip6tables');

		foreach ($ipts as &$ipt) {
			if (file_exists("{$iptp}/{$ipt}")) {
				system("mv -f {$iptp}/{$ipt} {$iptp}/{$ipt}.kloxosave");
			}
		}

		if ($installfrom !== 'setup') {
			print("\n*** You are installing Kloxo-MR (Kloxo fork by Mustafa Ramadhan) ***\n");
			print("- Better using backup-restore process for update from Kloxo 6.1.12+.\n");
			print("  No guarantee always success update from Kloxo after 6.1.12 version\n\n");


			//--- Ask License
			if (get_yes_no("Kloxo is using AGPL-V3.0 License, do you agree with the terms?") == 'n') {
				print("You did not agree to the AGPL-V3.0 license terms.\n");
				print("Installation aborted.\n\n");
				exit;
			} else {
				print("Installing Kloxo-MR = YES\n\n");
			}
		}
	}

	// MR -- disable asking for installing installapp where installapp not installed now
/*
	//--- Ask for InstallApp
	print("InstallApp: PHP Applications like PHPBB, WordPress, Joomla etc\n");
	print("When you choose Yes, be aware of downloading about 350Mb of data!\n");

	if (get_yes_no("Do you want to install the InstallAPP sotfware?") == 'n') {
		print("Installing InstallApp = NO\n");
		print("You can install it later with /script/installapp-update\n\n");
		$installappinst = false;
	} else {
		print("Installing InstallApp = YES\n\n");
		$installappinst = true;
	}
*/

	kloxo_install_step1();

	if ($kloxostate === 'none') {
		install_main();
		kloxo_install_step2();
	}

	kloxo_vpopmail();

	kloxo_prepare_kloxo_httpd_dir();

/*
	if ($installappinst) {
		kloxo_install_installapp();
	}
*/

	kloxo_install_before_bye();

	if ($kloxostate === 'none') {
	//	system("sh /script/cleanup");
	}

	if ($installtype === 'master') {
		if (file_exists("/var/lib/mysql/kloxo")) {
			kloxo_install_bye();
		}
	} else {
		kloxo_install_bye();
	}

	system("chkconfig hiawatha off; hiawatha stop; rm -f /etc/init.d/hiawatha");
	copy("{$kloxopath}/init/kloxo.init", "/etc/init.d/kloxo");
	system("chmod 755 /etc/init.d/kloxo; chkconfig kloxo on");

	system("/etc/init.d/kloxo restart >/dev/null 2>&1 &");
}

// ==== kloxo_all portion ===

function install_general_mine($value)
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	$value = implode(" ", $value);
	print("Installing $value ....\n");
	system("yum -y install $value");
}

function installcomp_mail()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	system('pear channel-update "pear.php.net"'); // to remove old channel warning
	system("pear upgrade --force pear"); // force is needed
	system("pear upgrade --force Archive_Tar"); // force is needed
	system("pear upgrade --force structures_graph"); // force is needed
	system("pear install log");
}

function install_main()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	print("Prepare defaults and configurations...\n");
	
	// MR -- remove qmail-lxcenter not here! - need outside script
	$packages = array("sendmail", "sendmail-cf", "sendmail-doc", "sendmail-devel",
		"vsftpd", "postfix", "ssmtp", "lxzend", "pure-ftpd");

	$list = implode(" ", $packages);
	print("Removing packages $list...\n");

	foreach ($packages as $package) {
		system("rpm -e --nodeps $package > /dev/null 2>&1");
	}

	// MR -- use 'rpm -e' not work perfectly; use 'yum remove' and also install depedencies again
	// (webalizer, cronie, cronie-anacron and crontabs)
	system("yum -y remove exim");
	
	$installcomp['mail'] = array("autorespond-toaster", "courier-authlib-toaster",
		"courier-imap-toaster", "daemontools-toaster", "ezmlm-toaster", "libdomainkeys-toaster",
		"libsrs2-toaster", "maildrop-toaster", "qmail-pop3d-toaster", "qmail-toaster",
		"ripmime-toaster", "ucspi-tcp-toaster", "vpopmail-toaster", "fetchmail", "bogofilter", "webalizer",
		"cronie", "cronie-anacron", "crontabs");


	$installcomp['web'] = array(getApacheBranch(), "mod_rpaf", "mod_ssl", "mod_fastcgi", "pure-ftpd");
//	$installcomp['dns'] = array("bind", "bind-chroot");
	$installcomp['dns'] = array("bind", "bind-utils");

	$mysqltmp = getMysqlBranch();
	$installcomp['database'] = array($mysqltmp, $mysqltmp."-server", $mysqltmp."-libs");

//	system("yum replace $mysqltmp --replace-with=mysql55 -y");

//	$comp = array("web", "database", "dns", "mail");

	// MR -- new install always 'none' for web and dns
	$comp = array("database", "mail");

	$serverlist = $comp;

	foreach ($comp as $c) {
		flush();

		if (array_search($c, $serverlist) !== false) {
			print("Installing $c Components....");
			$req = $installcomp[$c];
			$func = "installcomp_$c";

			if (function_exists($func)) {
				$func();
			}

			install_general_mine($req);
			print("\n");
		}
	}

	// MR -- remove httpd because need 'none' driver for web
	// httpd always installed when install php!
	system("rpm -e httpd --nodeps");
}

function kloxo_vpopmail()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	file_put_contents("/etc/sysconfig/spamassassin", "SPAMDOPTIONS=\" -v -d -p 783 -u vpopmail\"");

	print("\nCreating Vpopmail database...\n");

	if (file_exists("/home/vpopmail/etc")) {
		system("sh /usr/local/lxlabs/kloxo/bin/misc/vpop.sh $dbroot \"$dbpass\" vpopmail $mypass");
	}

	if (file_exists("/home/lxadmin/mail/etc")) {
		system("sh /usr/local/lxlabs/kloxo/bin/misc/lxpop.sh $dbroot \"$dbpass\" vpopmail $mypass");
	}

	// MR -- until Kloxo-MR 6.5.1, still using the same mail path
	system("mkdir -p /home/lxadmin/mail/domains");
	system("chmod 755 /home/lxadmin");
	system("chmod 755 /home/lxadmin/mail");
	system("chmod 755 /home/lxadmin/mail/domains");

	if (isRpmInstalled('qmail-toaster')) {
		system("chmod 755 /home/vpopmail");
		system("chmod 755 /home/vpopmail/domains");

		system("rm -f /etc/rc.d/init.d/courier-imap");
		system("rm -f /etc/rc.d/init.d/clamav");
		system("rm -f /etc/xinetd.d/smtp_lxa");
		system("rm -f /etc/xinetd.d/kloxo_smtp_lxa");
	}

	system("chmod -R 755 /var/log/httpd/ >/dev/null 2>&1");
	system("chmod -R 755 /var/log/httpd/fpcgisock >/dev/null 2>&1");
	system("mkdir -p /var/log/kloxo/");
	system("mkdir -p /var/log/news");
}

function kloxo_install_step1()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	if ($kloxostate === 'none') {
		print("Adding System users and groups (nouser, nogroup and lxlabs, lxlabs)\n");
		system("groupadd nogroup");
		system("useradd nouser -g nogroup -s '/sbin/nologin'");
		system("groupadd lxlabs");
		system("useradd lxlabs -g lxlabs -s '/sbin/nologin'");

		// MR -- force remove old lxphp (from lxcenter.repo)
	//	system("rpm -e lxphp-5.2.1-400.i386 --nodeps > /dev/null 2>&1");

		if (isRpmInstalled('qmail-toaster')) {
			// MR -- force remove spamassassin, qmail and vpopmail (because using toaster)
			system("userdel lxpopuser > /dev/null 2>&1");
			system("groupdel lxpopgroup > /dev/null 2>&1");

			system("groupadd -g 89 vchkpw > /dev/null 2>&1");
			system("useradd -u 89 -G vchkpw vpopmail -s '/sbin/nologin' > /dev/null 2>&1");
		}

		if (!file_exists("/etc/rc.d/init.d/djbdns")) {
			$darr = array('axfrdns', 'dnscache', 'dnslog', 'tinydns');

			foreach ($darr as &$d) {
				system("rm -rf /home/{$d} > /dev/null 2>&1");
			}
		}

		// MR -- force remove postfix and their user
		system("userdel postfix > /dev/null 2>&1");

		// MR -- remove lxphp, lxlighttpd and lxzend
		system("yum remove -y lxphp lxlighttpd lxzend");
		system("rm -rf /usr/local/lxlabs/ext");

		// MR -- for accept for php and apache branch rpm
		$phpbranch = getPhpBranch();

		// MR -- xcache, zend, ioncube, suhosin and zts not default install
		$packages = array("tnef", "which", "gcc", "cpp", "gcc-c++", "zip", "unzip", "curl", "autoconf", "automake", "make",
			"libtool", "openssl-devel", "pure-ftpd", "yum-protectbase", "yum-plugin-replace", "crontabs",
			"make", "glibc-static", "net-snmp", "tmpwatch", "rkhunter", "quota", "traceroute", "util-linux",
			"{$phpbranch}", "{$phpbranch}-mbstring", "{$phpbranch}-mysql", "{$phpbranch}-pear",
			"{$phpbranch}-pecl-geoip", "{$phpbranch}-mcrypt", "{$phpbranch}-xml", "hiawatha", "php52s"
		);

		$list = implode(" ", $packages);

	/*
		while (true) {
			print("Installing generic packages $list...\n");
			system("yum -y install $list", $return_value);

			if (file_exists("/opt/php52s/bin/php")) {
				break;
			} else {
				print("YUM Gave Error... Trying Again...\n");
				if (get_yes_no("Try again?") == 'n') {
					print("- EXIT: Fix the problem and install Kloxo-MR again.\n");
					exit;
				}
			}
		}
	*/
		system("yum -y install $list");

		$php52modinst = "/usr/local/lxlabs/kloxo/pscript/php52s-extension-install";

		system("sh {$php52modinst} php52-xcache");
		system("sh {$php52modinst} php52-pecl-geoip");

		system("ln -s /opt/php52s/bin/php-cgi /opt/php52s/bin/kloxo-phpcgi");

		if (!file_exists("/usr/bin/lxphp.exe")) {
			system("ln -s /opt/php52s/bin/php /usr/bin/lxphp.exe");
		}
		
		// MR -- install kloxomr specific rpms
	//	$packages = array("kloxomr-webmail-*.noarch", "kloxomr-addon-*.noarch",
	//		"kloxomr-thirdparty-*.noarch", "kloxomr-stats-*.noarch"
	//	);
	
		$packages = array("kloxomr-webmail-*.noarch", "kloxomr-thirdparty-*.noarch", "kloxomr-stats-*.noarch");
		
		$list = implode(" ", $packages);

		print("Installing Kloxo-MR packages $list...\n");

		system("yum -y install $list");
	}

	print("Prepare installation directory\n");

	system("mkdir -p {$kloxopath}");

	if ($installfrom !== 'setup') {
		if (file_exists("../../kloxomr-latest.tar.gz")) {
			//--- Install from local file if exists
			system("rm -f {$kloxopath}/kloxo-current.zip");
			system("rm -f {$kloxopath}/kloxo-mr-latest.zip");
			system("rm -f {$kloxopath}/kloxomr.tar.gz");

			print("Local copying Kloxo-MR release\n");
			system("mkdir -p /var/cache/kloxo");
			system("cp -rf ../../kloxomr-latest.tar.gz {$kloxopath}");

			chdir("/usr/local/lxlabs/kloxo");
			system("mkdir -p {$kloxopath}/log");
		} else {
			chdir("/usr/local/lxlabs/kloxo");
			system("mkdir -p {$kloxopath}/log");

			system("rm -f {$kloxopath}/kloxo-current.zip");
			system("rm -f {$kloxopath}/kloxo-mr-latest.zip");
			system("rm -f {$kloxopath}/kloxomr.tar.gz");
		}
	}

	if (php_uname('m') === 'x86_64') {
		if (file_exists("/usr/lib/php")) {
			system("mv -f /usr/lib/php /usr/lib/php.bck");
		}

		$sls = array('php', 'httpd', 'lighttpd', 'nginx', 'mysql', 'perl');

		foreach ($sls as &$sl) {
			if (!file_exists("/usr/lib64/{$sl}")) {
				system("mkdir -p /usr/lib64/{$sl}");
			}

			if (!is_link("/usr/lib/{$sl}")) {
				system("ln -s /usr/lib64/{$sl} /usr/lib/{$sl}");
			}
		}
	}

	if ($installfrom !== 'setup') {
		print("\n\nInstalling Kloxo-MR.....\n\n");

		system("tar -xzf kloxomr-latest.tar.gz -C ../");
		system("rm -f {$kloxopath}/kloxomr-latest.tar.gz");
		system("mv -f ../kloxomr-* ../kloxomr");
		system("cp -rf ../kloxomr/* ../kloxo");
		system("rm -rf ../kloxomr");
	}

	system("chown -R lxlabs:lxlabs {$kloxopath}/cexe");
	system("chmod -R 755 {$kloxopath}/cexe");
	system("chmod -R ug+s {$kloxopath}/cexe");

	copy_script();

	system("chown -R lxlabs:lxlabs {$lxlabspath}");

	setUsingMyIsam();

	if (!isMysqlRunning()) {
		actionMySql('start');
	}
}

function kloxo_install_step2()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	if (!file_exists("{$kloxopath}/etc/conf")) {
		system("mkdir -p {$kloxopath}/etc/conf");
	}

	if (!file_exists("{$kloxopath}/etc/conf/kloxo.pass")) {
		system("echo '{$mypass}' > ${kloxopath}/etc/conf/kloxo.pass");
	}

	if (!file_exists("{$kloxopath}/etc/slavedb")) {
		system("mkdir -p {$kloxopath}/etc/slavedb");
	}

	if (!file_exists("{$kloxopath}/etc/slavedb/dbadmin")) {
		if (strlen($dbpass) === 0) {
			$dbpassins = '';
		} else {
			$dbpassins = $dbpass;
		}

		$dbadmindata = 'O:6:"Remote":1:{s:4:"data";a:1:{s:5:"mysql";a:1:{s:10:"dbpassword";s:' .
			strlen($dbpass) . ':"' . $dbpassins . '";}}}';
		system("echo '{$dbadmindata}' > {$kloxopath}/etc/slavedb/dbadmin");
	}

	if (!file_exists("{$kloxopath}/etc/slavedb/driver")) {
/*
		$driverdata = 'O:6:"Remote":1:{s:4:"data";a:4:{s:3:"web";s:6:"apache";' .
			's:4:"spam";s:10:"bogofilter";s:3:"dns";s:4:"bind";s:8:"webcache";s:4:"none";}}';
		system("echo '{$driverdata}' > {$kloxopath}/etc/slavedb/driver");
*/
		$driverdata = 'O:6:"Remote":1:{s:4:"data";a:4:{s:3:"web";s:4:"none";' .
			's:4:"spam";s:10:"bogofilter";s:3:"dns";s:4:"none";s:8:"webcache";s:4:"none";}}';
		system("echo '{$driverdata}' > {$kloxopath}/etc/slavedb/driver");

	}

	check_default_mysql();
	
	chdir("{$kloxopath}/httpdocs/");

	system("lxphp.exe {$kloxopath}/bin/install/create.php " .
		"--install-type={$installtype} --db-rootuser={$dbroot} --db-rootpassword={$dbpass}");
}

function kloxo_install_installapp()
{
	print("Install InstallApp...\n");
	system("/script/installapp-update"); // First run (gets installappdata)
	system("/script/installapp-update"); // Second run (gets applications)
}

function kloxo_prepare_kloxo_httpd_dir()
{
	print("Prepare /home/kloxo/httpd...\n");
	system("mkdir -p /home/kloxo/httpd");

	system("rm -f /home/kloxo/httpd/skeleton-disable.zip");

	system("chown -R apache:apache /home/kloxo/httpd");
}

function kloxo_install_before_bye()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	if (!isRpmInstalled('fetchmail')) {
		system("yum install fetchmail -y");
	}

	// MR -- because php 5.2 have problem with php-fpm
	if (version_compare(getPhpVersion(), "5.3.2", "<")) {
		$phpbranch = getPhpBranch();
		system("yum remove {$phpbranch}-fpm -y");
	}

	// MR -- use fastcgi instead ruid2 or mod_php as default
	if (file_exists("/etc/httpd/conf.d/php.conf")) {
		// MR -- because /home/apache no exist at this step
		// MR -- ruid2 need php.conf!
	//	system("mv -f /etc/httpd/conf.d/php.conf /etc/httpd/conf.d/php.nonconf");
	//	system("cp -rf {$kloxopath}/file/apache/etc/conf.d/ruid2.conf /etc/httpd/conf.d/ruid2.conf");
		system("cp -rf {$kloxopath}/file/apache/etc/conf.d/fastcgi.conf /etc/httpd/conf.d/fastcgi.conf");
		system("cp -rf {$kloxopath}/file/apache/etc/conf.d/_inactive_.conf /etc/httpd/conf.d/php.conf");
		exec("echo 'HTTPD=/usr/sbin/httpd.event' >/etc/sysconfig/httpd");

	}

	//--- Prevent mysql socket problem (especially on 64bit system)
	if (!file_exists("/var/lib/mysql/mysql.sock")) {
		print("Create mysql.sock...\n");
		actionMysql('stop');
		system("mksock /var/lib/mysql/mysql.sock");
		actionMysql('start');
	}

	//--- Set ownership for Kloxo httpdocs dir
	system("chown -R lxlabs:lxlabs {$kloxopath}/httpdocs");

	// must be start status for guarantee next process running well
	if (!isMysqlRunning()) {
		//--- Prevent for Mysql not start after reboot for fresh kloxo slave install
		print("Setting Mysql for always running after reboot and restart now...\n");

		actionMysql('start');
	}
}

function kloxo_install_bye()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;
	
		$t  = "\n";
		$t .= " _/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/ "."\n";
		$t .= " _/                                                                          _/ "."\n";
		$t .= " _/ Congratulations. Kloxo-MR has been installed succesfully as 'MASTER'     _/ "."\n";
		$t .= " _/                                                                          _/ "."\n";

	if ($installtype === 'master') {
		$t .= " _/ You can connect to the server at:                                        _/ "."\n";
		$t .= " _/ https://<ip-address>:7777 - secure ssl connection, or                    _/ "."\n";
		$t .= " _/ http://<ip-address>:7778 - normal one.                                   _/ "."\n";
		$t .= " _/                                                                          _/ "."\n";
		$t .= " _/ The login and password are 'admin' and 'admin' for new install.          _/ "."\n";
		$t .= " _/ After Logging in, you will have to change your password to               _/ "."\n";
		$t .= " _/ something more secure.                                                   _/ "."\n";
		$t .= " _/                                                                          _/ "."\n";
	} else {
		$t .= " _/ You should open the port 7779 on this server, since this is used for     _/ "."\n";
		$t .= " _/ the communication between master and slave                               _/ "."\n";
		$t .= " _/                                                                          _/ "."\n";
		$t .= " _/ To access this slave, to go admin->servers->add server,                  _/ "."\n";
		$t .= " _/ give the ip/machine name of this server. The password is 'admin'.        _/ "."\n";
		$t .= " _/                                                                          _/ "."\n";
		$t .= " _/ The slave will appear in the list of slaves, and you can access it       _/ "."\n";
		$t .= " _/ just like you access localhost                                           _/ "."\n";
		$t .= " _/                                                                          _/ "."\n";
	}

	if ($kloxostate !== 'none') {
		$t .= " _/ - Need running 'sh /script/cleanup' for update                           _/ "."\n";
	}

	if ($installstep === '2') {
		$t .= " _/ - Better reboot for fresh install                                        _/ "."\n";
		$t .= " _/ - Run 'sh /script/make-slave' for change to 'SLAVE'                      _/ "."\n";
	}

	if (isRpmInstalled('qmail')) {
		$t .= " _/ - Run 'sh /script/convert-to-qmailtoaster' to convert qmail-toaster      _/ "."\n";
	}

		$t .= " _/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/_/ "."\n";
		$t .= "\n";

	print($t);
}

// ==== kloxo_common portion ===

// MR -- this class must be exist for slave_get_db_pass()
class remote
{
}

function parse_opt($argv)
{
	unset($argv[0]);

	if (!$argv) {
		return null;
	}

	$ret = null;

	foreach ($argv as $v) {
		if (strstr($v, "=") === false || strstr($v, "--") === false) {
			continue;
		}

		$opt = explode("=", $v);
		$opt[0] = substr($opt[0], 2);
		$ret[$opt[0]] = $opt[1];
	}

	return $ret;
}

function password_gen()
{
	return randomString(10);
}

function char_search_beg($haystack, $needle)
{
	if (strpos($haystack, $needle) === 0) {
		return true;
	} else {
		return false;
	}
}

function install_yum_repo()
{
	print("\nModified MRatWork repos and then install some packages...\n\n");

	if (!file_exists("/etc/yum.repos.d")) {
		print("No yum.repos.d dir detected!\n");

		return;
	}

	if (!file_exists("/etc/yum.repos.d/kloxo-mr.repo")) {
		system("cp -rf ./kloxo-mr.repo /etc/yum.repos.d/kloxo-mr.repo");
	}
	
	// MR -- just to know @ exist or not because centos 6 change 'installed' to '@'
	exec("yum list *yum*|grep @", $out, $ret);

	// MR -- need for OS (like fedora) where os version not the same with redhat/centos
	if ($out) {
		system("sed -i 's/\$releasever/6/' /etc/yum.repos.d/kloxo-mr.repo");
	} else {
		system("sed -i 's/\$releasever/5/' /etc/yum.repos.d/kloxo-mr.repo");
	}

	// MR -- remove all old repos
	system("rm -f /etc/yum.repos.d/kloxo.repo");
	system("rm -f /etc/yum.repos.d/kloxo-custom.repo");
	system("rm -f /etc/yum.repos.d/lxcenter.repo");

	system("yum clean all");
}

function find_os_version()
{
	// list os support
	$ossup = array('redhat' => 'rhel', 'fedora' => 'fedora', 'centos' => 'centos');

	$osrel = null;

	foreach (array_keys($ossup) as $k) {
		$osrel = file_get_contents("/etc/{$k}-release");
		if ($osrel) {
			$osrel = strtolower(trim($osrel));
			break;
		}
	}

	// specific for 'red hat'
	$osrel = str_replace('red hat', 'redhat', $osrel);

	$osver = explode(" ", $osrel);

	$verpos = sizeof($osver) - 2;

	if (array_key_exists($osver[0], $ossup)) {
		// specific for 'red hat'
		if ($osrel === 'redhat') {
			$oss = $osver[$verpos];
		} else {
			$mapos = explode(".", $osver[$verpos]);
			$oss = $mapos[0];
		}

		return $ossup[$osver[0]] . "-" . $oss;
	} else {
		print("This Operating System is currently *NOT* supported.\n");

		exit;
	}
}

function get_yes_no($question, $default = 'n')
{
	if ($default != 'y') {
		$default = 'n';
		$question .= ' [y/N]: ';
	} else {
		$question .= ' [Y/n]: ';
	}
	for (; ;) {
		print $question;
		flush();
		$input = fgets(STDIN, 255);
		$input = trim($input);
		$input = strtolower($input);

		if ($input == 'y' || $input == 'yes' || ($default == 'y' && $input == '')) {
			return 'y';
		} else if ($input == 'n' || $input == 'no' || ($default == 'n' && $input == '')) {
			return 'n';
		}
	}
}

function addLineIfNotExist($filename, $pattern, $comment)
{

	if (file_exists($filename)) {
		$cont = file_get_contents($filename);
	} else {
		$cont = '';
	}

	if (!preg_match("+$pattern+i", $cont)) {
		file_put_contents($filename, "\n$comment \n\n", FILE_APPEND);
		file_put_contents($filename, $pattern, FILE_APPEND);
		file_put_contents($filename, "\n\n\n", FILE_APPEND);
	} else {
		print("Pattern '$pattern' Already present in $filename\n");
	}
}

// MR -- taken from lib.php
function getPhpBranch()
{
	$a = array('php', 'php52', 'php53', 'php53u', 'php54');

	foreach ($a as &$e) {
		if (isRpmInstalled($e)) {
			return $e;
		}
	}

	return 'php';
}

// MR -- taken from lib.php
function getApacheBranch()
{
	$a = array('httpd', 'httpd24');

	foreach ($a as &$e) {
		if (isRpmInstalled($e)) {
			return $e;
		}
	}

	return 'httpd';
}

// MR -- taken from lib.php
function getMysqlBranch()
{
	$a = array('mysql', 'mysql50', 'mysql51', 'mysql53', 'mysql55', 'mariadb', 'MariaDB');

	foreach ($a as &$e) {
		if (isRpmInstalled($e . '-server')) {
			return $e;
		}
	}

	return 'mysql';
}

// MR -- taken from lib.php
function getRpmVersion($rpmname)
{
	exec("rpm -q --qf '%{VERSION}\n' {$rpmname}", $out, $ret);

	if ($out[0] !== false) {
		$ver = $out[0];
	} else {
		$ver = '';
	}

	return $ver;
}

// MR -- taken from lib.php
function getPhpVersion()
{
	exec("php -r 'echo phpversion();'", $out, $ret);

	return $out[0];
}

// MR -- taken from lib.php
function isRpmInstalled($rpmname)
{
	exec("rpm -q {$rpmname}", $out);

	$ret = strpos($out[0], "{$rpmname}-");

	// MR -- must be '!== 0' because no exist sometimes with value > 0; 0 because position in 0
	if ($ret !== 0) {
		return false;
	} else {
		return true;
	}
}

function setUsingMyIsam()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	// MR -- taken from mysql-convert.php with modified
	// to make fresh install already use myisam as storage engine
	// with purpose minimize memory usage (save around 100MB)

	$mysqlver = getRpmVersion('mysql');

	if (version_compare($mysqlver, '5.5.0', ">=")) {
		// MR -- MySQL (also MariaDB) no permit 'skip-innodb'
		return false;
	}

	if ($kloxostate === 'none') {
		$file = "/etc/my.cnf";

		$string = file_get_contents($file);

		$string_array = explode("\n", $string);

		$string_collect = null;

		foreach ($string_array as $sa) {
			if (stristr($sa, 'skip-innodb') !== FALSE) {
				$string_collect .= "";
				continue;
			}

			if (stristr($sa, 'default-storage-engine') !== FALSE) {
				$string_collect .= "";
				continue;
			}
			$string_collect .= $sa . "\n";
		}

		$string_source = "[mysqld]\n";
		$string_replace = "[mysqld]\nskip-innodb\ndefault-storage-engine=myisam\n";

		$string_collect = str_replace($string_source, $string_replace, $string_collect);

		file_put_contents($file, $string_collect);
	}
}

function isMysqlRunning()
{
	if (file_exists("/etc/rc.d/init.d/mysql")) {
		exec("service mysql status|grep -i '(pid'", $out);
	} else {
		exec("service mysqld status|grep -i '(pid'", $out);
	}

	if ($out) {
		return true;
	} else {
		return false;
	}
}

function actionMysql($action)
{
	if (file_exists("/etc/rc.d/init.d/mysql")) {
		system("service mysql {$action}");
	} else {
		system("service mysqld {$action}");
	}
}

function copy_script()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	system("mkdir -p /script/filter");

	system("cp -rf {$kloxopath}/pscript/* /script/");

	file_put_contents("/script/programname", 'kloxo');
	system("chmod 0775 /script");
}

function getKloxoType()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	if (file_exists("{$kloxopath}/etc/conf/slave-db.db")) {
		return 'slave';
	} else {
		if (file_exists("/var/lib/mysql/kloxo")) {
			return 'master';
		} else {
			return 'none';
		}
	}
}

function check_default_mysql()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	if (!isMysqlRunning()) {
		actionMySql('start');
	}

	if ($dbpass !== '') {
		exec("echo \"show tables\" | mysql -u {$dbroot} -p\"{$dbpass}\" mysql", $out, $return);
	} else {
		exec("echo \"show tables\" | mysql -u {$dbroot} mysql", $out, $return);
	}

	if ($return) {
		resetDBPassword();
	}
}

function resetDBPassword()
{
	global $argv;
	global $lxlabspath, $kloxopath, $currentstamp, $kloxostate;
	global $installtype, $installfrom, $installstep;
	global $currentpath, $dbroot, $dbpass, $mypass, $osversion;

	system("sh /script/reset-mysql-root-password {$dbpass}");
}

// taken from lxlib.php
function randomString($length)
{
	$randstr = '';

	$chars = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B', 'C',
		'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P',
		'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z');

	for ($rand = 0; $rand <= $length; $rand++) {
		$random = rand(0, count($chars) - 1);
		$randstr .= $chars[$random];
	}

	return $randstr;
}

lxins_main();
