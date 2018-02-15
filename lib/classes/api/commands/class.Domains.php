<?php

class Domains extends ApiCommand
{

	public function list()
	{
		if ($this->isAdmin()) {
			$this->logger()->logAction(ADM_ACTION, LOG_NOTICE, "[API] list domains");
			$result_stmt = Database::prepare("
				SELECT
				`d`.*, `c`.`loginname`, `c`.`deactivated`, `c`.`name`, `c`.`firstname`, `c`.`company`, `c`.`standardsubdomain`,
				`ad`.`id` AS `aliasdomainid`, `ad`.`domain` AS `aliasdomain`
				FROM `" . TABLE_PANEL_DOMAINS . "` `d`
				LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` USING(`customerid`)
				LEFT JOIN `" . TABLE_PANEL_DOMAINS . "` `ad` ON `d`.`aliasdomain`=`ad`.`id`
				WHERE `d`.`parentdomainid`='0' " . ($this->getUserDetail('customers_see_all') ? '' : " AND `d`.`adminid` = :adminid "));
			$params = array();
			if ($this->getUserDetail('customers_see_all') == '0') {
				$params['adminid'] = $this->getUserDetail('adminid');
			}
			Database::pexecute($result_stmt, $params);
			$result = array();
			while ($row = $result_stmt->fetch(PDO::FETCH_ASSOC)) {
				$result[] = $row;
			}
			return $this->response(200, "successfull", array(
				'count' => count($result),
				'list' => $result
			));
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function get()
	{
		if ($this->isAdmin()) {
			$id = $this->getParam('id');
			$no_std_subdomain = $this->getParam('no_std_subdomain', false);
			$this->logger()->logAction(ADM_ACTION, LOG_NOTICE, "[API] get domain #" . $id);
			$result_stmt = Database::prepare("
				SELECT `d`.*, `c`.`customerid`
				FROM `" . TABLE_PANEL_DOMAINS . "` `d`
				LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` USING(`customerid`)
				WHERE `d`.`parentdomainid` = '0'
				AND `d`.`id` = :id" . ($no_std_subdomain ? ' AND `d.`id` <> `c`.`standardsubdomain`' : '') . ($this->getUserDetail('customers_see_all') ? '' : " AND `d`.`adminid` = :adminid"));
			$params = array(
				'id' => $id
			);
			if ($this->getUserDetail('customers_see_all') == '0') {
				$params['adminid'] = $this->getUserDetail('adminid');
			}
			$result = Database::pexecute_first($result_stmt, $params, true, true);
			if ($result) {
				return $this->response(200, "successfull", $result);
			}
			throw new Exception("Domain with id #" . $id . " could not be found");
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function add()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings')) {
			if ($this->getUserDetail('domains_used') < $this->getUserDetail('domains') || $this->getUserDetail('domains') == '-1') {
				
				if ($this->getParam('domain') == Settings::Get('system.hostname')) {
					standard_error('admin_domain_emailsystemhostname', '', true);
				}
				
				if (substr($this->getParam('domain'), 0, 4) == 'xn--') {
					standard_error('domain_nopunycode', '', true);
				}
				
				$idna_convert = new idna_convert_wrapper();
				$domain = $idna_convert->encode(preg_replace(array(
					'/\:(\d)+$/',
					'/^https?\:\/\//'
				), '', validate($this->getParam('domain'), 'domain')));
				
				// Check whether domain validation is enabled and if, validate the domain
				if (Settings::Get('system.validate_domain') && ! validateDomain($domain)) {
					standard_error(array(
						'stringiswrong',
						'mydomain'
					), '', true);
				}
				
				$subcanemaildomain = $this->getParam('subcanemaildomain', 0);
				$isemaildomain = $this->getParam('isemaildomain', 0);
				$email_only = $this->getParam('email_only', 0);
				$serveraliasoption = $this->getParam('selectserveralias', 0);
				$speciallogfile = $this->getParam('speciallogfile', 0);
				
				$aliasdomain = intval($this->getParam('alias'));
				$issubof = intval($this->getParam('issubof'));
				$customerid = intval($this->getParam('customerid'));
				$customer_stmt = Database::prepare("
					SELECT * FROM `" . TABLE_PANEL_CUSTOMERS . "`
					WHERE `customerid` = :customerid " . ($this->getUserDetail('customers_see_all') ? '' : " AND `adminid` = :adminid"));
				$params = array(
					'customerid' => $customerid
				);
				if ($this->getUserDetail('customers_see_all') == '0') {
					$params['adminid'] = $this->getUserDetail('adminid');
				}
				$customer = Database::pexecute_first($customer_stmt, $params, true, true);
				
				if (empty($customer) || $customer['customerid'] != $customerid) {
					standard_error('customerdoesntexist', '', true);
				}
				
				if ($this->getUserDetail('customers_see_all') == '1') {
					
					$adminid = intval($this->getParam('adminid'));
					$admin_stmt = Database::prepare("
						SELECT * FROM `" . TABLE_PANEL_ADMINS . "`
						WHERE `adminid` = :adminid AND (`domains_used` < `domains` OR `domains` = '-1')");
					$admin = Database::pexecute_first($admin_stmt, array(
						'adminid' => $adminid
					), true, true);
					
					if (empty($admin) || $admin['adminid'] != $adminid) {
						standard_error('admindoesntexist', '', true);
					}
				} else {
					$adminid = $this->getUserDetail('adminid');
					$admin = $this->getUserData();
				}
				
				// set default path if admin/reseller has "change_serversettings == false" but we still
				// need to respect the documentroot_use_default_value - setting
				$path_suffix = '';
				if (Settings::Get('system.documentroot_use_default_value') == 1) {
					$path_suffix = '/' . $domain;
				}
				$documentroot = makeCorrectDir($customer['documentroot'] . $path_suffix);
				
				$registration_date = trim($this->getParam('registration_date', ''));
				$registration_date = validate($registration_date, 'registration_date', '/^(19|20)\d\d[-](0[1-9]|1[012])[-](0[1-9]|[12][0-9]|3[01])$/', '', array(
					'0000-00-00',
					'0',
					''
				), true);
				if ($registration_date == '0000-00-00') {
					$registration_date = null;
				}
				
				$termination_date = trim($this->getParam('termination_date', ''));
				$termination_date = validate($termination_date, 'termination_date', '/^(19|20)\d\d[-](0[1-9]|1[012])[-](0[1-9]|[12][0-9]|3[01])$/', '', array(
					'0000-00-00',
					'0',
					''
				), true);
				if ($termination_date == '0000-00-00') {
					$termination_date = null;
				}
				
				if ($this->getUserDetail('change_serversettings') == '1') {
					
					$caneditdomain = $this->getParam('caneditdomain', 0);
					
					$isbinddomain = '0';
					$zonefile = '';
					if (Settings::Get('system.bind_enable') == '1') {
						$isbinddomain = $this->getParam('isbinddomain', 0);
						$zonefile = validate($this->getParam('zonefile', ''), 'zonefile', '', '', array(), true);
					}
					
					$dkim = intval($this->getParam('dkim', 0));
					
					$specialsettings = validate(str_replace("\r\n", "\n", $this->getParam('specialsettings', '')), 'specialsettings', '/^[^\0]*$/', '', array(), true);
					$notryfiles = $this->getParam('notryfiles', 0);
					validate($this->getParam('documentroot', ''), 'documentroot', '', '', array(), true);
					
					// If path is empty and 'Use domain name as default value for DocumentRoot path' is enabled in settings,
					// set default path to subdomain or domain name
					if ($this->getParam('documentroot', '') != '') {
						if (substr($this->getParam('documentroot'), 0, 1) != '/' && ! preg_match('/^https?\:\/\//', $this->getParam('documentroot'))) {
							$documentroot .= '/' . $this->getParam('documentroot');
						} else {
							$documentroot = $this->getParam('documentroot');
						}
					} elseif ($this->getParam('documentroot', '') == '' && Settings::Get('system.documentroot_use_default_value') == 1) {
						$documentroot = makeCorrectDir($customer['documentroot'] . '/' . $domain);
					}
				} else {
					$isbinddomain = '0';
					if (Settings::Get('system.bind_enable') == '1') {
						$isbinddomain = '1';
					}
					$caneditdomain = '1';
					$zonefile = '';
					$dkim = '0';
					$specialsettings = '';
					$notryfiles = '0';
				}
				
				if ($this->getUserDetail('caneditphpsettings') == '1' || $this->getUserDetail('change_serversettings') == '1') {
					
					$phpenabled = $this->getParam('phpenabled', 0);
					$openbasedir = $this->getParam('openbasedir', 0);
					
					if ((int) Settings::Get('system.mod_fcgid') == 1 || (int) Settings::Get('phpfpm.enabled') == 1) {
						$phpsettingid = $this->getParam('phpsettingid', 1);
						$phpsettingid_check_stmt = Database::prepare("
							SELECT * FROM `" . TABLE_PANEL_PHPCONFIGS . "`
							WHERE `id` = :phpsettingid");
						$phpsettingid_check = Database::pexecute_first($phpsettingid_check_stmt, array(
							'phpsettingid' => $phpsettingid
						), true, true);
						
						if (! isset($phpsettingid_check['id']) || $phpsettingid_check['id'] == '0' || $phpsettingid_check['id'] != $phpsettingid) {
							standard_error('phpsettingidwrong', '', true);
						}
						
						if ((int) Settings::Get('system.mod_fcgid') == 1) {
							$mod_fcgid_starter = validate($this->getParam('mod_fcgid_starter', - 1), 'mod_fcgid_starter', '/^[0-9]*$/', '', array(
								'-1',
								''
							), true);
							$mod_fcgid_maxrequests = validate($this->getParam('mod_fcgid_maxrequests', - 1), 'mod_fcgid_maxrequests', '/^[0-9]*$/', '', array(
								'-1',
								''
							), true);
						} else {
							$mod_fcgid_starter = '-1';
							$mod_fcgid_maxrequests = '-1';
						}
					} else {
						
						if ((int) Settings::Get('phpfpm.enabled') == 1) {
							$phpsettingid = Settings::Get('phpfpm.defaultini');
						} else {
							$phpsettingid = Settings::Get('system.mod_fcgid_defaultini');
						}
						$mod_fcgid_starter = '-1';
						$mod_fcgid_maxrequests = '-1';
					}
				} else {
					
					$phpenabled = '1';
					$openbasedir = '1';
					
					if ((int) Settings::Get('phpfpm.enabled') == 1) {
						$phpsettingid = Settings::Get('phpfpm.defaultini');
					} else {
						$phpsettingid = Settings::Get('system.mod_fcgid_defaultini');
					}
					$mod_fcgid_starter = '-1';
					$mod_fcgid_maxrequests = '-1';
				}
				
				if ($this->getUserDetail('ip') != "-1") {
					$admin_ip_stmt = Database::prepare("
						SELECT `id`, `ip`, `port` FROM `" . TABLE_PANEL_IPSANDPORTS . "`
						WHERE `id` = :id ORDER BY `ip`, `port` ASC");
					$admin_ip = Database::pexecute_first($admin_ip_stmt, array(
						'id' => $this->getUserDetail('ip')
					), true, true);
					$additional_ip_condition = " AND `ip` = :adminip ";
					$aip_param = array(
						'adminip' => $admin_ip['ip']
					);
				} else {
					$additional_ip_condition = '';
					$aip_param = array();
				}
				
				$ipandports = array();
				if (! empty($this->getParam('ipandport')) && ! is_array($this->getParam('ipandport'))) {
					$this->updateParam('ipandport', unserialize($this->getParam('ipandport')));
				}
				
				if (! empty($this->getParam('ipandport')) && is_array($this->getParam('ipandport'))) {
					foreach ($this->getParam('ipandport') as $ipandport) {
						$ipandport = intval($ipandport);
						$ipandport_check_stmt = Database::prepare("
							SELECT `id`, `ip`, `port` FROM `" . TABLE_PANEL_IPSANDPORTS . "`
							WHERE `id` = :id " . $additional_ip_condition);
						$ip_params = null;
						$ip_params = array_merge(array(
							'id' => $ipandport
						), $aip_param);
						$ipandport_check = Database::pexecute_first($ipandport_check_stmt, $ip_params, true, true);
						
						if (! isset($ipandport_check['id']) || $ipandport_check['id'] == '0' || $ipandport_check['id'] != $ipandport) {
							standard_error('ipportdoesntexist', '', true);
						} else {
							$ipandports[] = $ipandport;
						}
					}
				}
				
				if (Settings::Get('system.use_ssl') == "1" && ! empty($this->getParam('ssl_ipandport'))) {
					$ssl_redirect = $this->getParam('ssl_redirect', 0);
					$letsencrypt = $this->getParam('letsencrypt', 0);
					
					$ssl_ipandports = array();
					if (! empty($this->getParam('ssl_ipandport')) && ! is_array($this->getParam('ssl_ipandport'))) {
						$this->updateParam('ssl_ipandport', unserialize($this->getParam('ssl_ipandport')));
					}
					
					// Verify SSL-Ports
					if (! empty($this->getParam('ssl_ipandport')) && is_array($this->getParam('ssl_ipandport'))) {
						foreach ($this->getParam('ssl_ipandport') as $ssl_ipandport) {
							if (trim($ssl_ipandport) == "") {
								continue;
							}
							// fix if no ssl-ip/port is checked
							if (trim($ssl_ipandport) < 1) {
								continue;
							}
							$ssl_ipandport = intval($ssl_ipandport);
							$ssl_ipandport_check_stmt = Database::prepare("
										SELECT `id`, `ip`, `port` FROM `" . TABLE_PANEL_IPSANDPORTS . "`
										WHERE `id` = :id " . $additional_ip_condition);
							$ip_params = null;
							$ip_params = array_merge(array(
								'id' => $ssl_ipandport
							), $aip_param);
							$ssl_ipandport_check = Database::pexecute_first($ssl_ipandport_check_stmt, $ip_params, true, true);
							
							if (! isset($ssl_ipandport_check['id']) || $ssl_ipandport_check['id'] == '0' || $ssl_ipandport_check['id'] != $ssl_ipandport) {
								standard_error('ipportdoesntexist', '', true);
							} else {
								$ssl_ipandports[] = $ssl_ipandport;
							}
						}
						
						$http2 = $this->getParam('http2', 0);
						// HSTS
						$hsts_maxage = $this->getParam('hsts_maxage', 0);
						$hsts_sub = $this->getParam('hsts_sub', 0);
						$hsts_preload = $this->getParam('hsts_preload', 0);
						// OCSP stapling
						$ocsp_stapling = $this->getParam('ocsp_stapling', 0);
					} else {
						$ssl_redirect = 0;
						$letsencrypt = 0;
						$http2 = 0;
						// we need this for the serialize
						// if ssl is disabled or no ssl-ip/port exists
						$ssl_ipandports[] = - 1;
						
						// HSTS
						$hsts_maxage = 0;
						$hsts_sub = 0;
						$hsts_preload = 0;
						
						// OCSP stapling
						$ocsp_stapling = 0;
					}
				} else {
					$ssl_redirect = 0;
					$letsencrypt = 0;
					$http2 = 0;
					// we need this for the serialize
					// if ssl is disabled or no ssl-ip/port exists
					$ssl_ipandports[] = - 1;
					
					// HSTS
					$hsts_maxage = 0;
					$hsts_sub = 0;
					$hsts_preload = 0;
					
					// OCSP stapling
					$ocsp_stapling = 0;
				}
				
				// We can't enable let's encrypt for wildcard - domains if using acme-v1
				if ($serveraliasoption == '0' && $letsencrypt == '1' && Settings::Get('system.leapiversion') == '1') {
					standard_error('nowildcardwithletsencrypt', '', true);
				}
				// if using acme-v2 we cannot issue wildcard-certificates
				// because they currently only support the dns-01 challenge
				if ($serveraliasoption == '0' && $letsencrypt == '1' && Settings::Get('system.leapiversion') == '2') {
					standard_error('nowildcardwithletsencryptv2', '', true);
				}
				
				// Temporarily deactivate ssl_redirect until Let's Encrypt certificate was generated
				if ($ssl_redirect > 0 && $letsencrypt == 1) {
					$ssl_redirect = 2;
				}
				
				if (! preg_match('/^https?\:\/\//', $documentroot)) {
					if (strstr($documentroot, ":") !== false) {
						standard_error('pathmaynotcontaincolon', '', true);
					} else {
						$documentroot = makeCorrectDir($documentroot);
					}
				}
				
				$domain_check_stmt = Database::prepare("
					SELECT `id`, `domain` FROM `" . TABLE_PANEL_DOMAINS . "`
					WHERE `domain` = :domain");
				$domain_check = Database::pexecute_first($domain_check_stmt, array(
					'domain' => strtolower($domain)
				), true, true);
				$aliasdomain_check = array(
					'id' => 0
				);
				
				if ($aliasdomain != 0) {
					// Overwrite given ipandports with these of the "main" domain
					$ipandports = array();
					$ssl_ipandports = array();
					$origipresult_stmt = Database::prepare("
						SELECT `id_ipandports` FROM `" . TABLE_DOMAINTOIP . "`
						WHERE `id_domain` = :id");
					Database::pexecute($origipresult_stmt, array(
						'id' => $aliasdomain
					), true, true);
					$ipdata_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_IPSANDPORTS . "` WHERE `id` = :ipid");
					while ($origip = $origipresult_stmt->fetch(PDO::FETCH_ASSOC)) {
						$_origip_tmp = Database::pexecute_first($ipdata_stmt, array(
							'ipid' => $origip['id_ipandports']
						), true, true);
						if ($_origip_tmp['ssl'] == 0) {
							$ipandports[] = $origip['id_ipandports'];
						} else {
							$ssl_ipandports[] = $origip['id_ipandports'];
						}
					}
					
					if (count($ssl_ipandports) == 0) {
						// we need this for the serialize
						// if ssl is disabled or no ssl-ip/port exists
						$ssl_ipandports[] = - 1;
					}
					
					$aliasdomain_check_stmt = Database::prepare("
						SELECT `d`.`id` FROM `" . TABLE_PANEL_DOMAINS . "` `d`, `" . TABLE_PANEL_CUSTOMERS . "` `c`
						WHERE `d`.`customerid` = :customerid
						AND `d`.`aliasdomain` IS NULL AND `d`.`id` <> `c`.`standardsubdomain`
						AND `c`.`customerid` = :customerid
						AND `d`.`id` = :aliasdomainid");
					$alias_params = array(
						'customerid' => $customerid,
						'aliasdomainid' => $aliasdomain
					);
					$aliasdomain_check = Database::pexecute_first($aliasdomain_check_stmt, $alias_params, true, true);
				}
				
				if (count($ipandports) == 0) {
					standard_error('noipportgiven', '', true);
				}
				
				if ($phpenabled != '1') {
					$phpenabled = '0';
				}
				
				if ($openbasedir != '1') {
					$openbasedir = '0';
				}
				
				if ($speciallogfile != '1') {
					$speciallogfile = '0';
				}
				
				if ($isbinddomain != '1') {
					$isbinddomain = '0';
				}
				
				if ($isemaildomain != '1') {
					$isemaildomain = '0';
				}
				
				if ($email_only == '1') {
					$isemaildomain = '1';
				} else {
					$email_only = '0';
				}
				
				if ($subcanemaildomain != '1' && $subcanemaildomain != '2' && $subcanemaildomain != '3') {
					$subcanemaildomain = '0';
				}
				
				if ($dkim != '1') {
					$dkim = '0';
				}
				
				if ($serveraliasoption != '1' && $serveraliasoption != '2') {
					$serveraliasoption = '0';
				}
				
				if ($caneditdomain != '1') {
					$caneditdomain = '0';
				}
				
				if ($issubof <= '0') {
					$issubof = '0';
				}
				
				if ($domain == '') {
					standard_error(array(
						'stringisempty',
						'mydomain'
					), '', true);
				} elseif ($documentroot == '') {
					standard_error(array(
						'stringisempty',
						'mydocumentroot'
					), '', true);
				} elseif ($customerid == 0) {
					standard_error('adduserfirst', '', true);
				} elseif (strtolower($domain_check['domain']) == strtolower($domain)) {
					standard_error('domainalreadyexists', $idna_convert->decode($domain), true);
				} elseif ($aliasdomain_check['id'] != $aliasdomain) {
					standard_error('domainisaliasorothercustomer', '', true);
				} else {
					
					/**
					 *
					 * @todo how to handle security questions now?
					 *      
					 *       $params = array(
					 *       'page' => $page,
					 *       'action' => $action,
					 *       'domain' => $domain,
					 *       'customerid' => $customerid,
					 *       'adminid' => $adminid,
					 *       'documentroot' => $documentroot,
					 *       'alias' => $aliasdomain,
					 *       'isbinddomain' => $isbinddomain,
					 *       'isemaildomain' => $isemaildomain,
					 *       'email_only' => $email_only,
					 *       'subcanemaildomain' => $subcanemaildomain,
					 *       'caneditdomain' => $caneditdomain,
					 *       'zonefile' => $zonefile,
					 *       'dkim' => $dkim,
					 *       'speciallogfile' => $speciallogfile,
					 *       'selectserveralias' => $serveraliasoption,
					 *       'ipandport' => serialize($ipandports),
					 *       'ssl_redirect' => $ssl_redirect,
					 *       'ssl_ipandport' => serialize($ssl_ipandports),
					 *       'phpenabled' => $phpenabled,
					 *       'openbasedir' => $openbasedir,
					 *       'phpsettingid' => $phpsettingid,
					 *       'mod_fcgid_starter' => $mod_fcgid_starter,
					 *       'mod_fcgid_maxrequests' => $mod_fcgid_maxrequests,
					 *       'specialsettings' => $specialsettings,
					 *       'notryfiles' => $notryfiles,
					 *       'registration_date' => $registration_date,
					 *       'termination_date' => $termination_date,
					 *       'issubof' => $issubof,
					 *       'letsencrypt' => $letsencrypt,
					 *       'http2' => $http2,
					 *       'hsts_maxage' => $hsts_maxage,
					 *       'hsts_sub' => $hsts_sub,
					 *       'hsts_preload' => $hsts_preload,
					 *       'ocsp_stapling' => $ocsp_stapling
					 *       );
					 *      
					 *       $security_questions = array(
					 *       'reallydisablesecuritysetting' => ($openbasedir == '0' && $userinfo['change_serversettings'] == '1'),
					 *       'reallydocrootoutofcustomerroot' => (substr($documentroot, 0, strlen($customer['documentroot'])) != $customer['documentroot'] && ! preg_match('/^https?\:\/\//', $documentroot))
					 *       );
					 *       $question_nr = 1;
					 *       foreach ($security_questions as $question_name => $question_launch) {
					 *       if ($question_launch !== false) {
					 *       $params[$question_name] = $question_name;
					 *      
					 *       if (! isset($_POST[$question_name]) || $_POST[$question_name] != $question_name) {
					 *       ask_yesno('admin_domain_' . $question_name, $filename, $params, $question_nr);
					 *       }
					 *       }
					 *       $question_nr ++;
					 *       }
					 */
					
					$wwwserveralias = ($serveraliasoption == '1') ? '1' : '0';
					$iswildcarddomain = ($serveraliasoption == '0') ? '1' : '0';
					
					$ins_data = array(
						'domain' => $domain,
						'customerid' => $customerid,
						'adminid' => $adminid,
						'documentroot' => $documentroot,
						'aliasdomain' => ($aliasdomain != 0 ? $aliasdomain : null),
						'zonefile' => $zonefile,
						'dkim' => $dkim,
						'wwwserveralias' => $wwwserveralias,
						'iswildcarddomain' => $iswildcarddomain,
						'isbinddomain' => $isbinddomain,
						'isemaildomain' => $isemaildomain,
						'email_only' => $email_only,
						'subcanemaildomain' => $subcanemaildomain,
						'caneditdomain' => $caneditdomain,
						'phpenabled' => $phpenabled,
						'openbasedir' => $openbasedir,
						'speciallogfile' => $speciallogfile,
						'specialsettings' => $specialsettings,
						'notryfiles' => $notryfiles,
						'ssl_redirect' => $ssl_redirect,
						'add_date' => time(),
						'registration_date' => $registration_date,
						'termination_date' => $termination_date,
						'phpsettingid' => $phpsettingid,
						'mod_fcgid_starter' => $mod_fcgid_starter,
						'mod_fcgid_maxrequests' => $mod_fcgid_maxrequests,
						'ismainbutsubto' => $issubof,
						'letsencrypt' => $letsencrypt,
						'http2' => $http2,
						'hsts' => $hsts_maxage,
						'hsts_sub' => $hsts_sub,
						'hsts_preload' => $hsts_preload,
						'ocsp_stapling' => $ocsp_stapling
					);
					
					$ins_stmt = Database::prepare("
						INSERT INTO `" . TABLE_PANEL_DOMAINS . "` SET
						`domain` = :domain,
						`customerid` = :customerid,
						`adminid` = :adminid,
						`documentroot` = :documentroot,
						`aliasdomain` = :aliasdomain,
						`zonefile` = :zonefile,
						`dkim` = :dkim,
						`dkim_id` = '0',
						`dkim_privkey` = '',
						`dkim_pubkey` = '',
						`wwwserveralias` = :wwwserveralias,
						`iswildcarddomain` = :iswildcarddomain,
						`isbinddomain` = :isbinddomain,
						`isemaildomain` = :isemaildomain,
						`email_only` = :email_only,
						`subcanemaildomain` = :subcanemaildomain,
						`caneditdomain` = :caneditdomain,
						`phpenabled` = :phpenabled,
						`openbasedir` = :openbasedir,
						`speciallogfile` = :speciallogfile,
						`specialsettings` = :specialsettings,
						`notryfiles` = :notryfiles,
						`ssl_redirect` = :ssl_redirect,
						`add_date` = :add_date,
						`registration_date` = :registration_date,
						`termination_date` = :termination_date,
						`phpsettingid` = :phpsettingid,
						`mod_fcgid_starter` = :mod_fcgid_starter,
						`mod_fcgid_maxrequests` = :mod_fcgid_maxrequests,
						`ismainbutsubto` = :ismainbutsubto,
						`letsencrypt` = :letsencrypt,
						`http2` = :http2,
						`hsts` = :hsts,
						`hsts_sub` = :hsts_sub,
						`hsts_preload` = :hsts_preload,
						`ocsp_stapling` = :ocsp_stapling
					");
					Database::pexecute($ins_stmt, $ins_data, true, true);
					$domainid = Database::lastInsertId();
					
					$upd_stmt = Database::prepare("
						UPDATE `" . TABLE_PANEL_ADMINS . "` SET `domains_used` = `domains_used` + 1
						WHERE `adminid` = :adminid");
					Database::pexecute($upd_stmt, array(
						'adminid' => $adminid
					), true, true);
					
					$ins_stmt = Database::prepare("
						INSERT INTO `" . TABLE_DOMAINTOIP . "` SET
						`id_domain` = :domainid,
						`id_ipandports` = :ipandportsid
					");
					
					foreach ($ipandports as $ipportid) {
						$ins_data = array(
							'domainid' => $domainid,
							'ipandportsid' => $ipportid
						);
						Database::pexecute($ins_stmt, $ins_data, true, true);
					}
					
					foreach ($ssl_ipandports as $ssl_ipportid) {
						if ($ssl_ipportid > 0) {
							$ins_data = array(
								'domainid' => $domainid,
								'ipandportsid' => $ssl_ipportid
							);
							Database::pexecute($ins_stmt, $ins_data, true, true);
						}
					}
					
					triggerLetsEncryptCSRForAliasDestinationDomain($aliasdomain, $this->logger());
					
					inserttask('1');
					// Using nameserver, insert a task which rebuilds the server config
					inserttask('4');
					
					$this->logger()->logAction(ADM_ACTION, LOG_WARNING, "[API] added domain '" . $domain . "'");
					return $this->response(200, "successfull", $ins_data);
				}
			}
			throw new Exception("No more resources available", 406);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function update()
	{
		if ($this->isAdmin() && $this->getUserDetail('change_serversettings')) {
			$id = $this->getParam('id');
			
			$json_result = Domains::getLocal($this->getUserData(), array(
				'id' => $id,
				'no_std_subdomain' => true
			))->get();
			$result = json_decode($json_result, true)['data'];
			
			$customer_stmt = Database::prepare("
				SELECT * FROM " . TABLE_PANEL_CUSTOMERS . " WHERE `customerid` = :customerid
			");
			$customer = Database::pexecute_first($customer_stmt, array(
				'customerid' => $result['customerid']
			));
			
			$customerid = $this->getParam('customerid', $result['customerid']);
			
			if ($customerid > 0 && $customerid != $result['customerid'] && Settings::Get('panel.allow_domain_change_customer') == '1') {
				
				$customer_stmt = Database::prepare("
					SELECT * FROM `" . TABLE_PANEL_CUSTOMERS . "`
					WHERE `customerid` = :customerid
					AND (`subdomains_used` + :subdomains <= `subdomains` OR `subdomains` = '-1' )
					AND (`emails_used` + :emails <= `emails` OR `emails` = '-1' )
					AND (`email_forwarders_used` + :forwarders <= `email_forwarders` OR `email_forwarders` = '-1' )
					AND (`email_accounts_used` + :accounts <= `email_accounts` OR `email_accounts` = '-1' ) " . ($this->getUserDetail('customers_see_all') ? '' : " AND `adminid` = :adminid"));
				
				$params = array(
					'customerid' => $customerid,
					'subdomains' => $subdomains,
					'emails' => $emails,
					'forwarders' => $email_forwarders,
					'accounts' => $email_accounts
				);
				if ($this->getUserDetail('customers_see_all') == '0') {
					$params['adminid'] = $this->getUserDetail('adminid');
				}
				
				$customer = Database::pexecute_first($customer_stmt, $params, true, true);
				if (empty($customer) || $customer['customerid'] != $customerid) {
					standard_error('customerdoesntexist', '', true);
				}
			} else {
				$customerid = $result['customerid'];
			}
			
			$customer_stmt = Database::prepare("
				SELECT * FROM " . TABLE_PANEL_ADMINS . " WHERE `adminid` = :adminid
			");
			$admin = Database::pexecute_first($customer_stmt, array(
				'adminid' => $result['adminid']
			), true, true);
			
			if ($this->getUserDetail('customers_see_all') == '1') {
				
				$adminid = $this->getParam('adminid', $result['adminid']);
				
				if ($adminid > 0 && $adminid != $result['adminid'] && Settings::Get('panel.allow_domain_change_admin') == '1') {
					
					$admin_stmt = Database::prepare("
						SELECT * FROM `" . TABLE_PANEL_ADMINS . "`
						WHERE `adminid` = :adminid AND ( `domains_used` < `domains` OR `domains` = '-1' )
					");
					$admin = Database::pexecute_first($admin_stmt, array(
						'adminid' => $adminid
					), true, true);
					
					if (empty($admin) || $admin['adminid'] != $adminid) {
						standard_error('admindoesntexist', '', true);
					}
				} else {
					$adminid = $result['adminid'];
				}
			} else {
				$adminid = $result['adminid'];
			}
			
			$aliasdomain = $this->getParam('alias', $result['aliasdomain']);
			$issubof = $this->getParam('issubof', $result['ismainbutsubto']);
			$subcanemaildomain = $this->getParam('subcanemaildomain', $result['subcanemaildomain']);
			$caneditdomain = $this->getParam('caneditdomain', $result['caneditdomain']);
			$registration_date = $this->getParam('registration_date', $result['registration_date']);
			$registration_date = validate($registration_date, 'registration_date', '/^(19|20)\d\d[-](0[1-9]|1[012])[-](0[1-9]|[12][0-9]|3[01])$/', '', array(
				'0000-00-00',
				'0',
				''
			), true);
			if ($registration_date == '0000-00-00') {
				$registration_date = null;
			}
			$termination_date = $this->getParam('termination_date', $result['termination_date']);
			$termination_date = validate($termination_date, 'termination_date', '/^(19|20)\d\d[-](0[1-9]|1[012])[-](0[1-9]|[12][0-9]|3[01])$/', '', array(
				'0000-00-00',
				'0',
				''
			), true);
			if ($termination_date == '0000-00-00') {
				$termination_date = null;
			}
			
			$isemaildomain = $this->getParam('isemaildomain', $result['isemaildomain']);
			$email_only = $this->getParam('email_only', $result['email_only']);
			
			$serveraliasoption = '2';
			if ($result['iswildcarddomain'] == '1') {
				$serveraliasoption = '0';
			} elseif ($result['wwwserveralias'] == '1') {
				$serveraliasoption = '1';
			}
			if (! empty($this->getParam('selectserveralias'))) {
				$serveraliasoption = intval($this->getParam('selectserveralias'));
			}
			
			$speciallogfile = $this->getParam('speciallogfile', $result['speciallogfile']);
			
			if ($this->getUserDetail('change_serversettings') == '1') {
				$isbinddomain = $result['isbinddomain'];
				$zonefile = $result['zonefile'];
				if (Settings::Get('system.bind_enable') == '1') {
					$isbinddomain = $this->getParam('isbinddomain', $result['isbinddomain']);
					$zonefile = validate($this->getParam('zonefile', $result['zonefile']), 'zonefile', '', '', array(), true);
				}
				
				if (Settings::Get('dkim.use_dkim') == '1') {
					$dkim = $this->getParam('dkim', $result['dkim']);
				} else {
					$dkim = $result['dkim'];
				}
				
				$specialsettings = validate(str_replace("\r\n", "\n", $this->getParam('specialsettings', $result['specialsettings'])), 'specialsettings', '/^[^\0]*$/', '', array(), true);
				$ssfs = $this->getParam('specialsettingsforsubdomains', 0);
				$notryfiles = $this->getParam('notryfiles', $result['notryfiles']);
				$documentroot = validate($this->getParam('documentroot', $result['documentroot']), 'documentroot', '', '', array(), true);
				
				if ($documentroot == '') {
					// If path is empty and 'Use domain name as default value for DocumentRoot path' is enabled in settings,
					// set default path to subdomain or domain name
					if (Settings::Get('system.documentroot_use_default_value') == 1) {
						$documentroot = makeCorrectDir($customer['documentroot'] . '/' . $result['domain']);
					} else {
						$documentroot = $customer['documentroot'];
					}
				}
				
				if (! preg_match('/^https?\:\/\//', $documentroot) && strstr($documentroot, ":") !== false) {
					standard_error('pathmaynotcontaincolon', '', true);
				}
			} else {
				$isbinddomain = $result['isbinddomain'];
				$zonefile = $result['zonefile'];
				$dkim = $result['dkim'];
				$specialsettings = $result['specialsettings'];
				$ssfs = (empty($specialsettings) ? 0 : 1);
				$notryfiles = $result['notryfiles'];
				$documentroot = $result['documentroot'];
			}
			
			// @TODO unsure whether this will still work
			$speciallogverified = $this->getParam('speciallogverified', 0);
			
			if ($this->getUserDetail('caneditphpsettings') == '1' || $this->getUserDetail('change_serversettings') == '1') {
				
				$phpenabled = $this->getParam('phpenabled', $result['phpenabled']);
				$openbasedir = $this->getParam('openbasedir', $result['openbasedir']);
				$phpfs = $this->getParam('phpsettingsforsubdomains', 0);
				
				if ((int) Settings::Get('system.mod_fcgid') == 1 || (int) Settings::Get('phpfpm.enabled') == 1) {
					$phpsettingid = $this->getParam('phpsettingid', $result['phpsettingid']);
					$phpsettingid_check_stmt = Database::prepare("
						SELECT * FROM `" . TABLE_PANEL_PHPCONFIGS . "` WHERE `id` = :phpid
					");
					$phpsettingid_check = Database::pexecute_first($phpsettingid_check_stmt, array(
						'phpid' => $phpsettingid
					), true, true);
					
					if (! isset($phpsettingid_check['id']) || $phpsettingid_check['id'] == '0' || $phpsettingid_check['id'] != $phpsettingid) {
						standard_error('phpsettingidwrong', '', true);
					}
					
					if ((int) Settings::Get('system.mod_fcgid') == 1) {
						$mod_fcgid_starter = validate($this->getParam('mod_fcgid_starter', $result['mod_fcgid_starter']), 'mod_fcgid_starter', '/^[0-9]*$/', '', array(
							'-1',
							''
						), true);
						$mod_fcgid_maxrequests = validate($this->getParam('mod_fcgid_maxrequests', $result['mod_fcgid_maxrequests']), 'mod_fcgid_maxrequests', '/^[0-9]*$/', '', array(
							'-1',
							''
						), true);
					} else {
						$mod_fcgid_starter = $result['mod_fcgid_starter'];
						$mod_fcgid_maxrequests = $result['mod_fcgid_maxrequests'];
					}
				} else {
					$phpsettingid = $result['phpsettingid'];
					$phpfs = 1;
					$mod_fcgid_starter = $result['mod_fcgid_starter'];
					$mod_fcgid_maxrequests = $result['mod_fcgid_maxrequests'];
				}
			} else {
				$phpenabled = $result['phpenabled'];
				$openbasedir = $result['openbasedir'];
				$phpsettingid = $result['phpsettingid'];
				$phpfs = 1;
				$mod_fcgid_starter = $result['mod_fcgid_starter'];
				$mod_fcgid_maxrequests = $result['mod_fcgid_maxrequests'];
			}
			
			$ipandports = array();
			if (! empty($this->getParam('ipandport')) && ! is_array($this->getParam('ipandport'))) {
				$this->updateParam('ipandport', unserialize($this->getParam('ipandport')));
			}
			
			if (! empty($this->getParam('ipandport')) && is_array($this->getParam('ipandport'))) {
				$ipandport_check_stmt = Database::prepare("
					SELECT `id`, `ip`, `port` FROM `" . TABLE_PANEL_IPSANDPORTS . "` WHERE `id` = :ipandport
				");
				foreach ($this->getParam('ipandport') as $ipandport) {
					if (trim($ipandport) == "") {
						continue;
					}
					$ipandport = intval($ipandport);
					$ipandport_check = Database::pexecute_first($ipandport_check_stmt, array(
						'ipandport' => $ipandport
					), true, true);
					if (! isset($ipandport_check['id']) || $ipandport_check['id'] == '0' || $ipandport_check['id'] != $ipandport) {
						standard_error('ipportdoesntexist', '', true);
					} else {
						$ipandports[] = $ipandport;
					}
				}
			}
			
			if (Settings::Get('system.use_ssl') == '1' && ! empty($this->getParam('ssl_ipandport'))) {
				$ssl = 1; // if ssl is set and != 0, it can only be 1
				$ssl_redirect = $this->getParam('ssl_redirect', $result['ssl_redirect']);
				$letsencrypt = $this->getParam('letsencrypt', $result['letsencrypt']);
				
				$ssl_ipandports = array();
				if (! empty($this->getParam('ssl_ipandport')) && ! is_array($this->getParam('ssl_ipandport'))) {
					$this->updateParam('ssl_ipandport', unserialize($this->getParam('ssl_ipandport')));
				}
				if (! empty($this->getParam('ssl_ipandport')) && is_array($this->getParam('ssl_ipandport'))) {
					$ssl_ipandport_check_stmt = Database::prepare("
						SELECT `id`, `ip`, `port` FROM `" . TABLE_PANEL_IPSANDPORTS . "` WHERE `id` = :ipandport
					");
					foreach ($this->getParam('ssl_ipandport') as $ssl_ipandport) {
						if (trim($ssl_ipandport) == "") {
							continue;
						}
						// fix if ip/port got de-checked and it was the last one
						if (trim($ssl_ipandport) < 1) {
							continue;
						}
						$ssl_ipandport = intval($ssl_ipandport);
						$ssl_ipandport_check = Database::pexecute_first($ssl_ipandport_check_stmt, array(
							'ipandport' => $ssl_ipandport
						), true, true);
						if (! isset($ssl_ipandport_check['id']) || $ssl_ipandport_check['id'] == '0' || $ssl_ipandport_check['id'] != $ssl_ipandport) {
							standard_error('ipportdoesntexist', '', true);
						} else {
							$ssl_ipandports[] = $ssl_ipandport;
						}
					}
					
					$http2 = $this->getParam('http2', $result['http2']);
					// HSTS
					$hsts_maxage = $this->getParam('hsts_maxage', $result['hsts_maxage']);
					$hsts_sub = $this->getParam('hsts_sub', $result['hsts_sub']);
					$hsts_preload = $this->getParam('hsts_preload', $result['hsts_preload']);
					// OCSP stapling
					$ocsp_stapling = $this->getParam('ocsp_stapling', $result['ocsp_stapling']);
				} else {
					$ssl_redirect = 0;
					$letsencrypt = 0;
					$http2 = 0;
					// we need this for the serialize
					// if ssl is disabled or no ssl-ip/port exists
					$ssl_ipandports[] = - 1;
					
					// HSTS
					$hsts_maxage = 0;
					$hsts_sub = 0;
					$hsts_preload = 0;
					
					// OCSP stapling
					$ocsp_stapling = 0;
				}
			} else {
				$ssl_redirect = 0;
				$letsencrypt = 0;
				$http2 = 0;
				// we need this for the serialize
				// if ssl is disabled or no ssl-ip/port exists
				$ssl_ipandports[] = - 1;
				
				// HSTS
				$hsts_maxage = 0;
				$hsts_sub = 0;
				$hsts_preload = 0;
				
				// OCSP stapling
				$ocsp_stapling = 0;
			}
			
			// We can't enable let's encrypt for wildcard domains when using acme-v1
			if ($serveraliasoption == '0' && $letsencrypt == '1' && Settings::Get('system.leapiversion') == '1') {
				standard_error('nowildcardwithletsencrypt', '', true);
			}
			// if using acme-v2 we cannot issue wildcard-certificates
			// because they currently only support the dns-01 challenge
			if ($serveraliasoption == '0' && $letsencrypt == '1' && Settings::Get('system.leapiversion') == '2') {
				standard_error('nowildcardwithletsencryptv2', '', true);
			}
			
			// Temporarily deactivate ssl_redirect until Let's Encrypt certificate was generated
			if ($ssl_redirect > 0 && $letsencrypt == 1 && $result['letsencrypt'] != $letsencrypt) {
				$ssl_redirect = 2;
			}
			
			if (! preg_match('/^https?\:\/\//', $documentroot)) {
				$documentroot = makeCorrectDir($documentroot);
			}
			
			if ($phpenabled != '1') {
				$phpenabled = '0';
			}
			
			if ($openbasedir != '1') {
				$openbasedir = '0';
			}
			
			if ($isbinddomain != '1') {
				$isbinddomain = '0';
			}
			
			if ($isemaildomain != '1') {
				$isemaildomain = '0';
			}
			
			if ($email_only == '1') {
				$isemaildomain = '1';
			} else {
				$email_only = '0';
			}
			
			if ($subcanemaildomain != '1' && $subcanemaildomain != '2' && $subcanemaildomain != '3') {
				$subcanemaildomain = '0';
			}
			
			if ($dkim != '1') {
				$dkim = '0';
			}
			
			if ($caneditdomain != '1') {
				$caneditdomain = '0';
			}
			
			$aliasdomain_check = array(
				'id' => 0
			);
			
			if ($aliasdomain != 0) {
				// Overwrite given ipandports with these of the "main" domain
				$ipandports = array();
				$ssl_ipandports = array();
				$origipresult_stmt = Database::prepare("
					SELECT `id_ipandports` FROM `" . TABLE_DOMAINTOIP . "` WHERE `id_domain` = :aliasdomain
				");
				Database::pexecute($origipresult_stmt, array(
					'aliasdomain' => $aliasdomain
				), true, true);
				$ipdata_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_IPSANDPORTS . "` WHERE `id` = :ipid");
				while ($origip = $origipresult_stmt->fetch(PDO::FETCH_ASSOC)) {
					$_origip_tmp = Database::pexecute_first($ipdata_stmt, array(
						'ipid' => $origip['id_ipandports']
					), true, true);
					if ($_origip_tmp['ssl'] == 0) {
						$ipandports[] = $origip['id_ipandports'];
					} else {
						$ssl_ipandports[] = $origip['id_ipandports'];
					}
				}
				
				if (count($ssl_ipandports) == 0) {
					// we need this for the serialize
					// if ssl is disabled or no ssl-ip/port exists
					$ssl_ipandports[] = - 1;
				}
				
				$aliasdomain_check_stmt = Database::prepare("
					SELECT `d`.`id` FROM `" . TABLE_PANEL_DOMAINS . "` `d`, `" . TABLE_PANEL_CUSTOMERS . "` `c`
					WHERE `d`.`customerid` = :customerid
					AND `d`.`aliasdomain` IS NULL AND `d`.`id` <> `c`.`standardsubdomain`
					AND `c`.`customerid` = :customerid
					AND `d`.`id` = :aliasdomain
				");
				$aliasdomain_check = Database::pexecute_first($aliasdomain_check_stmt, array(
					'customerid' => $customerid,
					'aliasdomain' => $aliasdomain
				), true, true);
			}
			
			if (count($ipandports) == 0) {
				standard_error('noipportgiven', '', true);
			}
			
			if ($aliasdomain_check['id'] != $aliasdomain) {
				standard_error('domainisaliasorothercustomer', '', true);
			}
			
			if ($issubof <= '0') {
				$issubof = '0';
			}
			
			if ($serveraliasoption != '1' && $serveraliasoption != '2') {
				$serveraliasoption = '0';
			}
			
			/**
			 *
			 * @todo how to handle security questions now?
			 *      
			 *       $params = array(
			 *       'id' => $id,
			 *       'page' => $page,
			 *       'action' => $action,
			 *       'customerid' => $customerid,
			 *       'adminid' => $adminid,
			 *       'documentroot' => $documentroot,
			 *       'alias' => $aliasdomain,
			 *       'isbinddomain' => $isbinddomain,
			 *       'isemaildomain' => $isemaildomain,
			 *       'email_only' => $email_only,
			 *       'subcanemaildomain' => $subcanemaildomain,
			 *       'caneditdomain' => $caneditdomain,
			 *       'zonefile' => $zonefile,
			 *       'dkim' => $dkim,
			 *       'selectserveralias' => $serveraliasoption,
			 *       'ssl_redirect' => $ssl_redirect,
			 *       'phpenabled' => $phpenabled,
			 *       'openbasedir' => $openbasedir,
			 *       'phpsettingid' => $phpsettingid,
			 *       'phpsettingsforsubdomains' => $phpfs,
			 *       'mod_fcgid_starter' => $mod_fcgid_starter,
			 *       'mod_fcgid_maxrequests' => $mod_fcgid_maxrequests,
			 *       'specialsettings' => $specialsettings,
			 *       'specialsettingsforsubdomains' => $ssfs,
			 *       'notryfiles' => $notryfiles,
			 *       'registration_date' => $registration_date,
			 *       'termination_date' => $termination_date,
			 *       'issubof' => $issubof,
			 *       'speciallogfile' => $speciallogfile,
			 *       'speciallogverified' => $speciallogverified,
			 *       'ipandport' => serialize($ipandports),
			 *       'ssl_ipandport' => serialize($ssl_ipandports),
			 *       'letsencrypt' => $letsencrypt,
			 *       'http2' => $http2,
			 *       'hsts_maxage' => $hsts_maxage,
			 *       'hsts_sub' => $hsts_sub,
			 *       'hsts_preload' => $hsts_preload,
			 *       'ocsp_stapling' => $ocsp_stapling
			 *       );
			 *      
			 *       $security_questions = array(
			 *       'reallydisablesecuritysetting' => ($openbasedir == '0' && $userinfo['change_serversettings'] == '1'),
			 *       'reallydocrootoutofcustomerroot' => (substr($documentroot, 0, strlen($customer['documentroot'])) != $customer['documentroot'] && ! preg_match('/^https?\:\/\//', $documentroot))
			 *       );
			 *       foreach ($security_questions as $question_name => $question_launch) {
			 *       if ($question_launch !== false) {
			 *       $params[$question_name] = $question_name;
			 *       if (! isset($_POST[$question_name]) || $_POST[$question_name] != $question_name) {
			 *       ask_yesno('admin_domain_' . $question_name, $filename, $params);
			 *       }
			 *       }
			 *       }
			 */
			
			$wwwserveralias = ($serveraliasoption == '1') ? '1' : '0';
			$iswildcarddomain = ($serveraliasoption == '0') ? '1' : '0';
			
			if ($documentroot != $result['documentroot'] || $ssl_redirect != $result['ssl_redirect'] || $wwwserveralias != $result['wwwserveralias'] || $iswildcarddomain != $result['iswildcarddomain'] || $phpenabled != $result['phpenabled'] || $openbasedir != $result['openbasedir'] || $phpsettingid != $result['phpsettingid'] || $mod_fcgid_starter != $result['mod_fcgid_starter'] || $mod_fcgid_maxrequests != $result['mod_fcgid_maxrequests'] || $specialsettings != $result['specialsettings'] || $notryfiles != $result['notryfiles'] || $aliasdomain != $result['aliasdomain'] || $issubof != $result['ismainbutsubto'] || $email_only != $result['email_only'] || ($speciallogfile != $result['speciallogfile'] && $speciallogverified == '1') || $letsencrypt != $result['letsencrypt'] || $http2 != $result['http2'] || $hsts_maxage != $result['hsts'] || $hsts_sub != $result['hsts_sub'] || $hsts_preload != $result['hsts_preload'] || $ocsp_stapling != $result['ocsp_stapling']) {
				inserttask('1');
			}
			
			if ($speciallogfile != $result['speciallogfile'] && $speciallogverified != '1') {
				$speciallogfile = $result['speciallogfile'];
			}
			
			if ($isbinddomain != $result['isbinddomain'] || $zonefile != $result['zonefile'] || $dkim != $result['dkim']) {
				inserttask('4');
			}
			
			if ($isemaildomain == '0' && $result['isemaildomain'] == '1') {
				$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_MAIL_USERS . "` WHERE `domainid` = :id
				");
				Database::pexecute($del_stmt, array(
					'id' => $id
				), true, true);
				
				$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_MAIL_VIRTUAL . "` WHERE `domainid` = :id
				");
				Database::pexecute($del_stmt, array(
					'id' => $id
				), true, true);
				$this->logger()->logAction(ADM_ACTION, LOG_NOTICE, "[API] deleted domain #" . $id . " from mail-tables as is-email-domain was set to 0");
			}
			
			// check whether LE has been disabled, so we remove the certificate
			if ($letsencrypt == '0' && $result['letsencrypt'] == '1') {
				$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` WHERE `domainid` = :id
				");
				Database::pexecute($del_stmt, array(
					'id' => $id
				), true, true);
			}
			
			$updatechildren = '';
			
			if ($subcanemaildomain == '0' && $result['subcanemaildomain'] != '0') {
				$updatechildren = ", `isemaildomain` = '0' ";
			} elseif ($subcanemaildomain == '3' && $result['subcanemaildomain'] != '3') {
				$updatechildren = ", `isemaildomain` = '1' ";
			}
			
			if ($customerid != $result['customerid'] && Settings::Get('panel.allow_domain_change_customer') == '1') {
				$upd_data = array(
					'customerid' => $customerid,
					'domainid' => $result['id']
				);
				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_MAIL_USERS . "` SET `customerid` = :customerid WHERE `domainid` = :domainid
				");
				Database::pexecute($upd_stmt, $upd_data, true, true);
				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_MAIL_VIRTUAL . "` SET `customerid` = :customerid WHERE `domainid` = :domainid
				");
				Database::pexecute($upd_stmt, $upd_data, true, true);
				$upd_data = array(
					'subdomains' => $subdomains,
					'emails' => $emails,
					'forwarders' => $email_forwarders,
					'accounts' => $email_accounts
				);
				$upd_data['customerid'] = $customerid;
				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
					`subdomains_used` = `subdomains_used` + :subdomains,
					`emails_used` = `emails_used` + :emails,
					`email_forwarders_used` = `email_forwarders_used` + :forwarders,
					`email_accounts_used` = `email_accounts_used` + :accounts
					WHERE `customerid` = :customerid
				");
				Database::pexecute($upd_stmt, $upd_data, true, true);
				
				$upd_data['customerid'] = $result['customerid'];
				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
					`subdomains_used` = `subdomains_used` - :subdomains,
					`emails_used` = `emails_used` - :emails,
					`email_forwarders_used` = `email_forwarders_used` - :forwarders,
					`email_accounts_used` = `email_accounts_used` - :accounts
					WHERE `customerid` = :customerid
				");
				Database::pexecute($upd_stmt, $upd_data, true, true);
			}
			
			if ($adminid != $result['adminid'] && Settings::Get('panel.allow_domain_change_admin') == '1') {
				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_ADMINS . "` SET `domains_used` = `domains_used` + 1 WHERE `adminid` = :adminid
				");
				Database::pexecute($upd_stmt, array(
					'adminid' => $adminid
				), true, true);
				
				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_ADMINS . "` SET `domains_used` = `domains_used` - 1 WHERE `adminid` = :adminid
				");
				Database::pexecute($upd_stmt, array(
					'adminid' => $result['adminid']
				), true, true);
			}
			
			$_update_data = array();
			
			$ssfs = $this->getParam('specialsettingsforsubdomains', 0);
			if ($ssfs == 1) {
				$_update_data['specialsettings'] = $specialsettings;
				$upd_specialsettings = ", `specialsettings` = :specialsettings ";
			} else {
				$upd_specialsettings = '';
				unset($_update_data['specialsettings']);
				$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `specialsettings`='' WHERE `parentdomainid` = :id
				");
				Database::pexecute($upd_stmt, array(
					'id' => $id
				), true, true);
				$this->logger()->logAction(ADM_ACTION, LOG_INFO, "[API] removed specialsettings on all subdomains of domain #" . $id);
			}
			
			$wwwserveralias = ($serveraliasoption == '1') ? '1' : '0';
			$iswildcarddomain = ($serveraliasoption == '0') ? '1' : '0';
			
			$update_data = array();
			$update_data['customerid'] = $customerid;
			$update_data['adminid'] = $adminid;
			$update_data['documentroot'] = $documentroot;
			$update_data['ssl_redirect'] = $ssl_redirect;
			$update_data['aliasdomain'] = ($aliasdomain != 0 && $alias_check == 0) ? $aliasdomain : null;
			$update_data['isbinddomain'] = $isbinddomain;
			$update_data['isemaildomain'] = $isemaildomain;
			$update_data['email_only'] = $email_only;
			$update_data['subcanemaildomain'] = $subcanemaildomain;
			$update_data['dkim'] = $dkim;
			$update_data['caneditdomain'] = $caneditdomain;
			$update_data['zonefile'] = $zonefile;
			$update_data['wwwserveralias'] = $wwwserveralias;
			$update_data['iswildcarddomain'] = $iswildcarddomain;
			$update_data['phpenabled'] = $phpenabled;
			$update_data['openbasedir'] = $openbasedir;
			$update_data['speciallogfile'] = $speciallogfile;
			$update_data['phpsettingid'] = $phpsettingid;
			$update_data['mod_fcgid_starter'] = $mod_fcgid_starter;
			$update_data['mod_fcgid_maxrequests'] = $mod_fcgid_maxrequests;
			$update_data['specialsettings'] = $specialsettings;
			$update_data['notryfiles'] = $notryfiles;
			$update_data['registration_date'] = $registration_date;
			$update_data['termination_date'] = $termination_date;
			$update_data['ismainbutsubto'] = $issubof;
			$update_data['letsencrypt'] = $letsencrypt;
			$update_data['http2'] = $http2;
			$update_data['hsts'] = $hsts_maxage;
			$update_data['hsts_sub'] = $hsts_sub;
			$update_data['hsts_preload'] = $hsts_preload;
			$update_data['ocsp_stapling'] = $ocsp_stapling;
			$update_data['id'] = $id;
			
			$update_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_DOMAINS . "` SET
				`customerid` = :customerid,
				`adminid` = :adminid,
				`documentroot` = :documentroot,
				`ssl_redirect` = :ssl_redirect,
				`aliasdomain` = :aliasdomain,
				`isbinddomain` = :isbinddomain,
				`isemaildomain` = :isemaildomain,
				`email_only` = :email_only,
				`subcanemaildomain` = :subcanemaildomain,
				`dkim` = :dkim,
				`caneditdomain` = :caneditdomain,
				`zonefile` = :zonefile,
				`wwwserveralias` = :wwwserveralias,
				`iswildcarddomain` = :iswildcarddomain,
				`phpenabled` = :phpenabled,
				`openbasedir` = :openbasedir,
				`speciallogfile` = :speciallogfile,
				`phpsettingid` = :phpsettingid,
				`mod_fcgid_starter` = :mod_fcgid_starter,
				`mod_fcgid_maxrequests` = :mod_fcgid_maxrequests,
				`specialsettings` = :specialsettings,
				`notryfiles` = :notryfiles,
				`registration_date` = :registration_date,
				`termination_date` = :termination_date,
				`ismainbutsubto` = :ismainbutsubto,
				`letsencrypt` = :letsencrypt,
				`http2` = :http2,
				`hsts` = :hsts,
				`hsts_sub` = :hsts_sub,
				`hsts_preload` = :hsts_preload,
				`ocsp_stapling` = :ocsp_stapling
				WHERE `id` = :id
			");
			Database::pexecute($update_stmt, $update_data, true, true);
			
			$_update_data['customerid'] = $customerid;
			$_update_data['adminid'] = $adminid;
			$_update_data['phpenabled'] = $phpenabled;
			$_update_data['openbasedir'] = $openbasedir;
			$_update_data['mod_fcgid_starter'] = $mod_fcgid_starter;
			$_update_data['mod_fcgid_maxrequests'] = $mod_fcgid_maxrequests;
			$_update_data['parentdomainid'] = $id;
			
			// if php config is to be set for all subdomains, check here
			$update_phpconfig = '';
			$phpfs = $this->getParam('phpsettingsforsubdomains', 0);
			if ($phpfs == 1) {
				$_update_data['phpsettingid'] = $phpsettingid;
				$update_phpconfig = ", `phpsettingid` = :phpsettingid";
			}
			
			// if we have no more ssl-ip's for this domain,
			// all its subdomains must have "ssl-redirect = 0"
			// and disable let's encrypt
			$update_sslredirect = '';
			if (count($ssl_ipandports) == 1 && $ssl_ipandports[0] == - 1) {
				$update_sslredirect = ", `ssl_redirect` = '0', `letsencrypt` = '0' ";
			}
			
			$_update_stmt = Database::prepare("
				UPDATE `" . TABLE_PANEL_DOMAINS . "` SET
				`customerid` = :customerid,
				`adminid` = :adminid,
				`phpenabled` = :phpenabled,
				`openbasedir` = :openbasedir,
				`mod_fcgid_starter` = :mod_fcgid_starter,
				`mod_fcgid_maxrequests` = :mod_fcgid_maxrequests
				" . $update_phpconfig . $upd_specialsettings . $updatechildren . $update_sslredirect . "
				WHERE `parentdomainid` = :parentdomainid
			");
			Database::pexecute($_update_stmt, $_update_data, true, true);
			
			// FIXME check how many we got and if the amount of assigned IP's
			// has changed so we can insert a config-rebuild task if only
			// the ip's of this domain were changed
			// -> for now, always insert a rebuild-task
			inserttask('1');
			
			// Cleanup domain <-> ip mapping
			$del_stmt = Database::prepare("
				DELETE FROM `" . TABLE_DOMAINTOIP . "` WHERE `id_domain` = :id
			");
			Database::pexecute($del_stmt, array(
				'id' => $id
			), true, true);
			
			$ins_stmt = Database::prepare("
				INSERT INTO `" . TABLE_DOMAINTOIP . "` SET `id_domain` = :domainid, `id_ipandports` = :ipportid
			");
			
			foreach ($ipandports as $ipportid) {
				Database::pexecute($ins_stmt, array(
					'domainid' => $id,
					'ipportid' => $ipportid
				), true, true);
			}
			foreach ($ssl_ipandports as $ssl_ipportid) {
				if ($ssl_ipportid > 0) {
					Database::pexecute($ins_stmt, array(
						'domainid' => $id,
						'ipportid' => $ssl_ipportid
					), true, true);
				}
			}
			
			// Cleanup domain <-> ip mapping for subdomains
			$domainidsresult_stmt = Database::prepare("
				SELECT `id` FROM `" . TABLE_PANEL_DOMAINS . "` WHERE `parentdomainid` = :id
			");
			Database::pexecute($domainidsresult_stmt, array(
				'id' => $id
			), true, true);
			
			while ($row = $domainidsresult_stmt->fetch(PDO::FETCH_ASSOC)) {
				
				$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_DOMAINTOIP . "` WHERE `id_domain` = :rowid
				");
				Database::pexecute($del_stmt, array(
					'rowid' => $row['id']
				), true, true);
				
				$ins_stmt = Database::prepare("
					INSERT INTO `" . TABLE_DOMAINTOIP . "` SET
					`id_domain` = :rowid,
					`id_ipandports` = :ipportid
				");
				
				foreach ($ipandports as $ipportid) {
					Database::pexecute($ins_stmt, array(
						'rowid' => $row['id'],
						'ipportid' => $ipportid
					), true, true);
				}
				foreach ($ssl_ipandports as $ssl_ipportid) {
					if ($ssl_ipportid > 0) {
						Database::pexecute($ins_stmt, array(
							'rowid' => $row['id'],
							'ipportid' => $ssl_ipportid
						), true, true);
					}
				}
			}
			if ($result['aliasdomain'] != $aliasdomain) {
				// trigger when domain id for alias destination has changed: both for old and new destination
				triggerLetsEncryptCSRForAliasDestinationDomain($result['aliasdomain'], $this->logger());
				triggerLetsEncryptCSRForAliasDestinationDomain($aliasdomain, $this->logger());
			} else if ($result['wwwserveralias'] != $wwwserveralias || $result['letsencrypt'] != $letsencrypt) {
				// or when wwwserveralias or letsencrypt was changed
				triggerLetsEncryptCSRForAliasDestinationDomain($aliasdomain, $this->logger());
			}
			
			$this->logger()->logAction(ADM_ACTION, LOG_WARNING, "[API] updated domain '" . $result['domain'] . "'");
			return $this->response(200, "successfull", $update_data);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}

	public function delete()
	{
		if ($this->isAdmin()) {
			$id = $this->getParam('id');
			
			$json_result = Domains::getLocal($this->getUserData(), array(
				'id' => $id,
				'no_std_subdomain' => true
			))->get();
			$result = json_decode($json_result, true)['data'];
			
			// check for deletion of main-domains which are logically subdomains, #329
			$rsd_sql = '';
			$remove_subbutmain_domains = $this->getParam('delete_userfiles', 0) ? 1 : 0;
			if ($remove_subbutmain_domains == 1) {
				$rsd_sql .= " OR `ismainbutsubto` = :id";
			}
			
			$subresult_stmt = Database::prepare("
					SELECT `id` FROM `" . TABLE_PANEL_DOMAINS . "`
					WHERE (`id` = :id OR `parentdomainid` = :id " . $rsd_sql . ")");
			Database::pexecute($subresult_stmt, array(
				'id' => $id
			), true, true);
			$idString = array();
			$paramString = array();
			while ($subRow = $subresult_stmt->fetch(PDO::FETCH_ASSOC)) {
				$idString[] = "`domainid` = :domain_" . (int) $subRow['id'];
				$paramString['domain_' . $subRow['id']] = $subRow['id'];
			}
			$idString = implode(' OR ', $idString);
			
			if ($idString != '') {
				$del_stmt = Database::prepare("
						DELETE FROM `" . TABLE_MAIL_USERS . "` WHERE " . $idString);
				Database::pexecute($del_stmt, $paramString, true, true);
				$del_stmt = Database::prepare("
						DELETE FROM `" . TABLE_MAIL_VIRTUAL . "` WHERE " . $idString);
				Database::pexecute($del_stmt, $paramString, true, true);
				$this->logger()->logAction(ADM_ACTION, LOG_NOTICE, "[API] deleted domain/s from mail-tables");
			}
			
			// if mainbutsubto-domains are not to be deleted, re-assign the (ismainbutsubto value of the main
			// domain which is being deleted) as their new ismainbutsubto value
			if ($remove_subbutmain_domains !== 1) {
				$upd_stmt = Database::prepare("
						UPDATE `" . TABLE_PANEL_DOMAINS . "` SET
						`ismainbutsubto` = :newIsMainButSubtoValue
						WHERE `ismainbutsubto` = :deletedMainDomainId
						");
				Database::pexecute($upd_stmt, array(
					'newIsMainButSubtoValue' => $result['ismainbutsubto'],
					'deletedMainDomainId' => $id
				), true, true);
			}
			
			$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_PANEL_DOMAINS . "`
					WHERE `id` = :id OR `parentdomainid` = :id " . $rsd_sql);
			Database::pexecute($del_stmt, array(
				'id' => $id
			), true, true);
			
			$deleted_domains = $del_stmt->rowCount();
			
			$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
					`subdomains_used` = `subdomains_used` - :domaincount
					WHERE `customerid` = :customerid");
			Database::pexecute($upd_stmt, array(
				'domaincount' => ($deleted_domains - 1),
				'customerid' => $result['customerid']
			), true, true);
			
			$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_ADMINS . "` SET
					`domains_used` = `domains_used` - 1
					WHERE `adminid` = :adminid");
			Database::pexecute($upd_stmt, array(
				'adminid' => $this->getUserDetail('adminid')
			), true, true);
			
			$upd_stmt = Database::prepare("
					UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET
					`standardsubdomain` = '0'
					WHERE `standardsubdomain` = :id AND `customerid` = :customerid");
			Database::pexecute($upd_stmt, array(
				'id' => $result['id'],
				'customerid' => $result['customerid']
			), true, true);
			
			$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_DOMAINTOIP . "`
					WHERE `id_domain` = :domainid");
			Database::pexecute($del_stmt, array(
				'domainid' => $id
			), true, true);
			
			$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_PANEL_DOMAINREDIRECTS . "`
					WHERE `did` = :domainid");
			Database::pexecute($del_stmt, array(
				'domainid' => $id
			), true, true);
			
			// remove certificate from domain_ssl_settings, fixes #1596
			$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
					WHERE `domainid` = :domainid");
			Database::pexecute($del_stmt, array(
				'domainid' => $id
			), true, true);
			
			// remove possible existing DNS entries
			$del_stmt = Database::prepare("
					DELETE FROM `" . TABLE_DOMAIN_DNS . "`
					WHERE `domain_id` = :domainid
				");
			Database::pexecute($del_stmt, array(
				'domainid' => $id
			), true, true);
			
			triggerLetsEncryptCSRForAliasDestinationDomain($result['aliasdomain'], $this->logger());
			
			$this->logger()->logAction(ADM_ACTION, LOG_INFO, "[API] deleted domain/subdomains (#" . $result['id'] . ")");
			updateCounters();
			inserttask('1');
			// Using nameserver, insert a task which rebuilds the server config
			inserttask('4');
			return $this->response(200, "successfull", $result);
		}
		throw new Exception("Not allowed to execute given command.", 403);
	}
}