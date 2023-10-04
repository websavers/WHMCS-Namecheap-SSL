<?php

// ****************************************************************************
// *                                                                          *
// * NameCheap.com WHMCS SSL Module Addon                                     *
// * Version 1.42
// * Email: sslsupport@namecheap.com                                          *
// *                                                                          *
// * Copyright 2010-2015 NameCheap.com                                        *
// *                                                                          *
// ****************************************************************************

if (!defined("WHMCS"))
	die("This file cannot be accessed directly");


require_once dirname(__FILE__) . "/../../../modules/servers/namecheapssl/namecheapapi.php";
require_once dirname(__FILE__) . "/../../../modules/servers/namecheapssl/namecheapssl.php";


/*
**********************************************

**********************************************
*/

function namecheap_ssl_config() {
    $configarray = array(
    "name" => "Namecheap SSL Module Addon",
    "description" => "This addon performs several important operations related to Namecheap SSL Module. 1) It performs necessary installation/update procedures during Namecheap SSL Module installation/update. 2) It logs details of all SSL certificate reissues. 3) It performs full logging of all API calls for the products with activated \"debug mode\" option.",
    "version" => "1.42",
    "author" => "Namecheap",
    "language" => "english",
    "fields" => array(
        "log_items_per_page" => array ("FriendlyName" => "Number of Log entries per page", "Type" => "text", "Size" => "2", "Description" => "items", "Default" => "50", ),
    )
    );
    return $configarray;
}

function namecheap_ssl_activate() {
    
    
    // 1. Create configuration email template
    if (!NcSql::sqlNumRows(sprintf("SELECT id FROM tblemailtemplates WHERE name='%s'",'SSL Certificate Configuration Required'))) {
        full_query("INSERT INTO `tblemailtemplates` (`type` ,`name` ,`subject` ,`message` ,`fromname` ,`fromemail` ,`disabled` ,`custom` ,`language` ,`copyto` ,`plaintext` )VALUES ('product', 'SSL Certificate Configuration Required', 'SSL Certificate Configuration Required', '<p>Dear {\$client_name},</p><p>Thank you for your order for an SSL Certificate. Before you can use your certificate, it requires configuration which can be done at the URL below.</p><p>{\$ssl_configuration_link}</p><p>Instructions are provided throughout the process but if you experience any problems or have any questions, please open a ticket for assistance.</p><p>{\$signature}</p>', '', '', '', '', '', '', '0')");
    }

    // 2.Create auxiliary module table    
    $queryString = " CREATE TABLE IF NOT EXISTS `mod_namecheapssl` (
                                      `id` INT AUTO_INCREMENT ,
                                      `user_id` INT ,
                                      `certificate_id` INT ,
                                      `type` VARCHAR( 255 ) ,
                                      `status` VARCHAR( 255 ) ,
                                      `creation_date` VARCHAR( 10 ) ,
                                      `period` INT( 1 ) ,
                                      `expiry_date` VARCHAR( 10 ) ,
                                      `domain` VARCHAR( 255 ),
                                      `parse_csr` TEXT,
                                      `admin_email` VARCHAR( 255 ),
                                      PRIMARY KEY ( `id` )
                        ) ENGINE = MYISAM ";
    NcSql::q($queryString);
    
    
    // 2. Create auxiliary module log table
    NcSql::q("CREATE TABLE IF NOT EXISTS `mod_namecheapssl_log` (
	`id` INT(10) NOT NULL AUTO_INCREMENT,
	`date` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
	`action` VARCHAR(255) NOT NULL DEFAULT '',
	`description` TEXT NOT NULL,
	`user` TEXT NOT NULL,
	`userid` INT(10) NOT NULL,
	`ipaddr` TEXT NOT NULL,
	PRIMARY KEY (`id`)
        )
        COLLATE='utf8_general_ci'
        ENGINE=MyISAM");

    // 2. Update existing auxiliary table: add reissue functionality
    if (!NcSql::sqlNumRows("SHOW COLUMNS FROM `mod_namecheapssl` LIKE 'reissue'")){
        NcSql::q("ALTER TABLE `mod_namecheapssl` ADD COLUMN `reissue` TINYINT(1) NULL DEFAULT '0' AFTER `admin_email`");
    }
    
    // 3. Add reissue invitation letter
    if (!NcSql::sqlNumRows("SELECT id FROM tblemailtemplates WHERE name='SSL Certificate Reissue Invitation'")){
        NcSql::q("INSERT INTO `tblemailtemplates` (`type` ,`name` ,`subject` ,`message` ,`fromname` ,`fromemail` ,`disabled` ,`custom` ,`language` ,`copyto` ,`plaintext` )VALUES ('product', 
            'SSL Certificate Reissue Invitation', 
            'SSL Certificate Reissue Invitation',
            '<p>Dear {\$client_name},</p><p>A reissue request has been initiated by an administrator for the following: {\$ssl_certificate_id}. In order to reissue the certificate please go through a configuration process at the URL below.</p><p>{\$ssl_configuration_link}</p><p>Instructions are provided throughout the process but if you experience any problems or have any questions, please open a ticket for assistance.</p><p>{\$signature}</p>',
            '', '', '', '', '', '', '0')");
    }
    
    
    namecheap_ssl_check_upgrades();
    
    return array(
        'status'=>'success',
        'description'=>''
    );
    

    # Return Result
    // return array('status'=>'success','description'=>'This is an demo module only. In a real module you might instruct a user how to get started with it here...');
    // return array('status'=>'error','description'=>'You can use the error status return to indicate there was a problem activating the module');
    

}

function namecheap_ssl_deactivate() {
    return array('status'=>'success','description'=>'');
    //return array('status'=>'error','description'=>'If an error occurs you can return an error message for display here');
    //return array('status'=>'info','description'=>'If you want to give an info message to a user you can return it here');
}


function namecheap_ssl_upgrade($vars) {
    
    namecheap_ssl_check_upgrades();
    
}


function namecheap_ssl_check_upgrades(){
    
    // v 1.1
    if (!NcSql::sqlNumRows("SHOW COLUMNS FROM `mod_namecheapssl_log` LIKE 'debug'")){
        NcSql::q("ALTER TABLE `mod_namecheapssl_log`	ADD COLUMN `debug` TINYINT(1) NOT NULL DEFAULT '0' AFTER `id`");
        NcSql::q("ALTER TABLE `mod_namecheapssl_log`	CHANGE COLUMN `user` `user` VARCHAR(255) NOT NULL AFTER `description`, ADD INDEX `debug` (`debug`), ADD INDEX `date` (`date`), ADD INDEX `action` (`action`), ADD INDEX `user` (`user`), ADD INDEX `userid` (`userid`)");
        NcSql::q("ALTER TABLE `mod_namecheapssl_log`	ADD COLUMN `parentid` INT(10) NOT NULL DEFAULT '0' AFTER `id`");
        NcSql::q("ALTER TABLE `mod_namecheapssl_log`	ADD COLUMN `serviceid` INT(10) NOT NULL DEFAULT '0' AFTER `parentid`");
    }
    
    // v 1.2
    if (!NcSql::sqlNumRows("SHOW COLUMNS FROM `mod_namecheapssl` LIKE 'file_name'")){
        NcSql::q("ALTER TABLE `mod_namecheapssl` DROP COLUMN `status`, DROP COLUMN `creation_date`, DROP COLUMN `expiry_date`, DROP COLUMN `domain`,	DROP COLUMN `parse_csr`");
        NcSql::q("ALTER TABLE `mod_namecheapssl` ADD COLUMN `file_name` VARCHAR(255) NULL AFTER `reissue`, ADD COLUMN `file_content` VARCHAR(255) NULL AFTER `file_name`");
    }
    
    // v 1.3
    // check updates only
    
    // v 1.4
    // 
    if (!NcSql::sqlNumRows("SHOW COLUMNS FROM `mod_namecheapssl` LIKE 'configdata_copy'")){
        NcSql::q("ALTER TABLE `mod_namecheapssl` ADD COLUMN `configdata_copy` TEXT NULL DEFAULT NULL AFTER `file_content`");
        NcSql::q("ALTER TABLE `mod_namecheapssl` ADD COLUMN `revoke_data` TEXT NULL DEFAULT NULL AFTER `configdata_copy`;");
    }
    
    
    // v 1.41
    // 
    if (!NcSql::sqlNumRows("SHOW TABLES LIKE 'mod_namecheapssl_settings'")){
        NcSql::q("CREATE TABLE `mod_namecheapssl_settings` (
                `name` VARCHAR(50) NOT NULL,
                `value` VARCHAR(255) NOT NULL
        )
        ENGINE=MyISAM");
    }
    
    
}



function namecheap_ssl_output($vars) {

    if (!empty($_REQUEST['action'])){
        $action = $_REQUEST['action'];
    }else{
        $action = 'default';
    }
    
    
    
    global $_LANG;
    namecheapssl_initlang();
    
    
    
    $view = array(
        'global' => array(
            'mod_url' => '?module=namecheap_ssl',
            'module' => 'namecheap_ssl'
        )
    );
    
    
    if ('log'==$action){
        
        // prepare data for actions filters        
        // actions
        $view['filter_action_options'] = NcSql::sql2set_column("SELECT DISTINCT action FROM mod_namecheapssl_log");
        
        // detect selected action
        if(!empty($_REQUEST['filter_action']) && in_array($_REQUEST['filter_action'],$view['filter_action_options'])){
            $view['filter_action_value'] = $_REQUEST['filter_action'];
        }else{
            $view['filter_action_value'] = '';
        }
        
        
        
        // 
        $view['filter_date_from_value'] = empty($_REQUEST['filter_date_from']) ? '' : $_REQUEST['filter_date_from'];
        $view['filter_date_to_value'] = empty($_REQUEST['filter_date_to']) ? '' : $_REQUEST['filter_date_to'];
        
        $view['filter_user_value'] = empty($_REQUEST['filter_user']) ? '' : $_REQUEST['filter_user'];
        
        
        // prepare query for page items
        $iOffset = empty($vars['log_items_per_page'])?50:(int)$vars['log_items_per_page']; 
        $page = !empty($_REQUEST['page']) ? (int)$_REQUEST['page'] : 1;    
        $iLimit = $page <= 1 ? 0 : ($page -1) * $iOffset;
        
        // create WHERE for sql query
        $sqlWhereArray = array();
        // action value
        if(!empty($view['filter_action_value'])){
            $sqlWhereArray[] = sprintf(" action='%s' " , NcSql::e($view['filter_action_value']));
        }
        // date from value
        if(!empty($view['filter_date_from_value'])){
            
            $sqlWhereArray[] = sprintf("date>='%s'",toMySQLDate($view['filter_date_from_value']));
        }
        // date to value
        if(!empty($view['filter_date_to_value'])){
            $sqlWhereArray[] = sprintf("date<='%s'",toMySQLDate($view['filter_date_to_value']).' 23:59:59');
        }
        // admin / client filter
        if(!empty($view['filter_user_value'])){
            if(false !==  strpos($view['filter_user_value'], '@')){
                $sqlWhereArray[] = sprintf("c.email = '%s'", NcSql::e($view['filter_user_value']));
            }else{
                $sqlWhereArray[] = sprintf("log.user LIKE '%s%%'", NcSql::e($view['filter_user_value']));
            }
        }
        
        if(!empty($sqlWhereArray)){
            $sqlWhere = ' WHERE ' .  implode(' AND ', $sqlWhereArray);
        }else{
            $sqlWhere = '';
        }
        
        
        $sql = "SELECT log.*,c.email FROM mod_namecheapssl_log log LEFT JOIN tblclients AS c ON (log.userid=c.id AND user='client') $sqlWhere ORDER BY log.id DESC LIMIT $iLimit,$iOffset";    
        
        
        $view['log_items'] = NcSql::sql2set($sql);
       
        // query for count
        $sql = "SELECT COUNT(log.id) FROM mod_namecheapssl_log log LEFT JOIN tblclients AS c ON (log.userid=c.id AND user='client') $sqlWhere" ;        
        $iCountOfLogItems = NcSql::sql2cell($sql);
        $iCountOfPages = (int)ceil($iCountOfLogItems/$iOffset);
        
        
        $view['log_items_count'] = $iCountOfLogItems;
        $view['log_items_count_of_pages'] = $iCountOfPages;
        $view['log_items_current_page'] = $page <= 1 ? 1:$page;
        
    }
    else if ('sync'==$action){
        
        if(!empty($_REQUEST['hostingid'])){
            
            $view['hostingid'] = (int)$_REQUEST['hostingid'];
            
            // search product            
            $row = NcSql::sql2row('SELECT orderid, tblhosting.domain, tblproducts.name AS productname FROM tblhosting JOIN tblproducts ON tblhosting.packageid=tblproducts.id WHERE tblhosting.id='.(int)$_REQUEST['hostingid']);
            
            // check san certificate
            // get config options
            $certHasSanOption = false;
            $r = NcSql::q('SELECT tblproductconfigoptions.optionname FROM tblproductconfigoptions JOIN tblhostingconfigoptions ON (tblhostingconfigoptions.configid=tblproductconfigoptions.id) WHERE tblhostingconfigoptions.relid='.(int)$_REQUEST['hostingid']);
            $optionNames = array();
            while($optionsRow=NcSql::fetchAssoc($r)){                
                $optionNames[] = $optionsRow['optionname'];
                if( 'san' == substr($optionsRow['optionname'],0,3)){
                    $certHasSanOption = true;
                }
            }
            
            
            $view['cert_has_san_option'] = $certHasSanOption;
            
            
            if(false==$row || $certHasSanOption){
                $view['found'] = false;
            }else{
                
                // select nc remote id
                $ssl_order = NcSql::sql2row('SELECT * FROM tblsslorders WHERE serviceid='.(int)$_REQUEST['hostingid']);
                
                if(false==$ssl_order){
                    $view['found'] = false;
                }else{
                    
                    $view['found'] = true;
                    $view['hosting'] = array(
                        'hostingid'=>$_REQUEST['hostingid'],
                        'orderid'=>$row['orderid'],
                        'domain'=>$row['domain'],
                        'productname'=>$row['productname'],
                        
                        'ssl_order_remoteid'=>$ssl_order['remoteid'],                        
                        'ssl_order_certtype'=>$ssl_order['certtype'],
                        'ssl_order_id'=>$ssl_order['id']
                        
                    );
                    
                    if(isset($_REQUEST['message']) && 'updated'==$_REQUEST['message']){
                        $view['updated'] = true;
                    }else{
                        $view['updated'] = false;
                    }
                    
                    
                    // final level verification
                    // assign remote id
                    if(!empty($_POST['remoteid'])&&!empty($_POST['ssl_order_id'])){
                        // two mysql queries
                        
                        // update whmcs native table
                        NcSql::q('UPDATE tblsslorders SET remoteid='.(int)$_POST['remoteid'].' WHERE id='.(int)$_POST['ssl_order_id']);
                        
                        // update custom module table
                        NcSql::q('UPDATE mod_namecheapssl SET certificate_id='.(int)$_POST['remoteid'].' WHERE id='.(int)$_POST['ssl_order_id']);
                        
                        // redirect
                        $query_string = '?module=namecheap_ssl&action=sync&hostingid='.$_REQUEST['hostingid'].'&message=updated';
                        
                        
                        namecheapssl_log('addon.sync', 'addon_updated_remoteid', array($ssl_order['remoteid'], $_POST['remoteid']),$ssl_order['serviceid']);
                        
                        header('Location: ' . $query_string);
                        exit();
                        
                    }
                    
                }
            }
            
            }else{
                $view['hostingid'] = '';
            }
        
    }
    else if ('list'==$action)
    {
        
        $users = array();
        
        // production certs
        $userList = NcSql::sql2set("SELECT DISTINCT configoption1 AS user, configoption2 AS password, 'production' AS acc FROM tblproducts WHERE configoption9='' AND configoption1!='' AND configoption2!='' AND servertype='namecheapssl'");        
        foreach($userList as $row){
            $view['userlist'][] = array(
                'user'=>$row['user'],
                'acc'=>'production'
                );
            $users['production'][$row['user']] = $row;
        }
            
        // sandbox users
        $userList = NcSql::sql2set("SELECT DISTINCT configoption3 AS user, configoption4 AS password, 'sandbox' AS acc FROM tblproducts WHERE configoption9='on' AND configoption3!='' AND configoption4!='' AND servertype='namecheapssl'");
        foreach($userList as $row){
            $view['userlist'][] = array(
                'user'=>$row['user'],
                'acc'=>'sandbox'
            );
            $users['sandbox'][$row['user']] = $row;
        }
        
        
        if(!empty($_REQUEST['user'])&&!empty($_REQUEST['acc'])){
            
            
            if('sandbox'!=$_REQUEST['acc']&&'production'!=$_REQUEST['acc']){
                echo 'unknown user';
                exit();
            }
            
            if(!empty($users[$_REQUEST['acc']][$_REQUEST['user']])){
                $user = $users[$_REQUEST['acc']][$_REQUEST['user']]['user'];
                $password = $users[$_REQUEST['acc']][$_REQUEST['user']]['password'];
            }else{
                echo 'unknown user';
                exit();
            }
            
            
            $view['user'] = array('user'=>$user,'acc'=>$_REQUEST['acc']);
            
            
            $itemsOnPage = 20;
            
            $page = empty($_REQUEST['page']) ? 1 : $_REQUEST['page'];
            $view['current_page'] = $page;
            
            $requestParams = array("Page" => $page, "PageSize" => $itemsOnPage);
            
            
            
            $api = new NamecheapApi($user, $password, $_REQUEST['acc']=='sandbox');
            
            try{
                $response = $api->request("namecheap.ssl.getList", $requestParams);
                $result = $api->parseResponse($response);
                
                if(!empty($result['SSLListResult']['SSL'])){
                    
                    $items = array();
                    foreach ($result['SSLListResult']['SSL'] as $k=>$item){
                        
                        // get whmcs product
                        $items[$k]['namecheap'] = $item['@attributes'];
                        
                        $query = sprintf("SELECT serviceid,status FROM tblsslorders WHERE module='namecheapssl' AND remoteid='%s'", (int)$item['@attributes']['CertificateID']);
                        $items[$k]['whmcs'] = NcSql::sql2row($query);
                        
                    }
                    
                    
                    $view['items'] = $items;
                }
                
                $view['pages'] = array();
                for($i=1;$i<=ceil($result['Paging']['TotalItems']/$itemsOnPage);++$i){
                    $view['pages'][] = $i;
                }
                
                
            }catch(Exception $e){
                $view['globals']['error'] = $e->getMessage();
            }
            
            
        }
        
    }
    else if ('settings'==$action)
    {
        
        // message
        $view['message'] = '';
        if(!empty($_REQUEST['message']) && 'updated' == $_REQUEST['message']){
            $view['message'] = $_LANG['ncssl_addon_changes_saved_success'];
        }
        
        // prepare information for view
        $view['settings'] = NcSql::sql2set_keyval("SELECT name,value FROM mod_namecheapssl_settings");
        
        
        $view['control_options'] = array(
            'sync_date_offset' => array(
                    0 => '0',
                    5 => '5',
                    15 => '15',
                    30 => '30'
                )
        );
        
        // process incoming data
        if(isset($_REQUEST['settings'])){
            
            foreach($_REQUEST['settings'] as $name=>$value){
                NcSql::q(sprintf("DELETE FROM mod_namecheapssl_settings WHERE name='%s'",  NcSql::e($name)));
                NcSql::q(sprintf("INSERT INTO mod_namecheapssl_settings SET name='%s', value='%s'",  NcSql::e($name),NcSql::e($value)));
            }
            
            // redirect
            $query_string = '?module=namecheap_ssl&action=settings&message=updated';
            
            namecheapssl_log('addon.settings', 'addon_updated_settings');
            

            header('Location: ' . $query_string);
            exit();
            
            
        }
       
    }
    else
    {
        $action = 'default';
    }
    
    
    $view['global']['mod_action_url'] = $view['global']['mod_url'] . '&action=' . $action;
    $view['global']['action'] = $action;

    include dirname(__FILE__) . '/templates/' . $action . '.php';
    
        
}

function namecheap_ssl_clientarea($vars){
    
    
    global $_LANG;
    namecheapssl_initlang();
    
    
    $vars = array();
    
    if(isset($_REQUEST['san_reduction'])){
        $vars['notice'] = $_LANG['ncssl_addon_sun_reduction_notice'];
    }
    
    if(!empty($_REQUEST['revoke_message'])){
        $vars['notice'] = $_LANG['ncssl_error_revoke_'.(int)$_REQUEST['revoke_message']];
    }
    
    if(!empty($_REQUEST['serviceid'])){
        $vars['back_to_service_id'] = (int)$_REQUEST['serviceid'];
    }
    
    
    return array(
        
        'pagetitle' => 'Namecheap SSL Addon Module',
        'breadcrumb' => array('index.php?m=namecheap_ssl'=>'Namecheap SSL Addon Module'),
        'templatefile' => 'client_templates/notice',
        'requirelogin' => true,
        'vars' => $vars
        
    );
    
}
