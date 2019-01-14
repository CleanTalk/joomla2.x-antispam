<?php

/**
 * CleanTalk joomla plugin
 *
 * @version 5.9
 * @package Cleantalk
 * @subpackage Joomla
 * @author CleanTalk (welcome@cleantalk.org) 
 * @copyright (C) 2016 Сleantalk team (http://cleantalk.org)
 * @license GNU/GPL: http://www.gnu.org/copyleft/gpl.html
 *
 */

defined('_JEXEC') or die('Restricted access');
jimport('joomla.plugin.plugin');
jimport('joomla.application.application');
jimport('joomla.application.component.helper');
if(!defined('DS'))
    define('DS', DIRECTORY_SEPARATOR);

require_once(dirname(__FILE__) . DS . 'cleantalk.class.php');
require_once(dirname(__FILE__) . DS . 'custom_config.php');

class plgSystemAntispambycleantalk extends JPlugin 
{
    /**
     * Plugin version string for server
     */
    const ENGINE = 'joomla3-59';

    /*
     * Flag marked JComments form initilization. 
     */
    private $JCReady = false;
    
    /**
     * Form submited without page load
     */
    private $ct_direct_post = 0;

    /**
     * Plugin id
     */
    private $_id;
     
    /**
     * Constructor
     * @access public
     * @param $subject
     * @param $config
     * @return void
     */
    public function __construct (&$subject, $config) {
        parent::__construct($subject, $config);

		// Get the plugin name.
		if (isset($config['name']))
		{
			$this->_name = $config['name'];
		}

		// Get the plugin type.
		if (isset($config['type']))
		{
			$this->_type = $config['type'];
		}
		// Get the plugin id.
		if (isset($config['id']))
		{
			$this->_id = $config['id'];
		}
		else $this->_id = $this->getId();        

        $this->loadLanguage();	
    } 

	private function getId()
	{
		$db=JFactory::getDBO();
		if(!version_compare(JVERSION, '3', 'ge')){ //joomla 2.5
		
			$sql='SELECT extension_id FROM #__extensions WHERE folder ="'.$db->getEscaped('system').'" AND element ="'.$db->getEscaped('antispambycleantalk').'"';
			$db->setQuery($sql);
			
		}else{
			
			$query = $db->getQuery(true);
			$query
				->select($db->quoteName('a.extension_id'))
				->from($db->quoteName('#__extensions', 'a'))
				->where($db->quoteName('a.element').' = '.$db->quote('antispambycleantalk'))
				->where($db->quoteName('a.folder').' = '.$db->quote('system'));
			$db->setQuery($query);
			$db->execute();
		}
		if(!($plg=$db->loadObject()))
			return 0;
		else
			return (int)$plg->extension_id;
		
	}

    private function cleantalk_get_checkjs_code()
    {
    	$config = $this->getCTConfig();
    	$keys = $config['js_keys'];
    	$keys_checksum = md5(json_encode($keys));

        $key = rand();
        $latest_key_time = 0;

        if ($keys && is_array($keys) && !empty($keys))
        {
	        foreach ($keys as $k => $t) {

	            // Removing key if it's to old
	            if (time() - $t > $config['js_keys_store_days'] * 86400) {
	                unset($keys[$k]);
	                continue;
	            }

	            if ($t > $latest_key_time) {
	                $latest_key_time = $t;
	                $key = $k;
	            }
	        }
	        // Get new key if the latest key is too old
	        if (time() - $latest_key_time > $config['js_key_lifetime']) {
	            $keys[$key] = time();
	        }	        
	    }
	    else $keys = array($key => time());
	                
        if (md5(json_encode($keys)) != $keys_checksum) {
        	$save_params['js_keys'] = $keys;
        	$this->saveCTConfig($save_params);
        }         	
                       
		return $key;	
    }  
	    
	/*
	* Checks if auth_key is paid or not
	*/
    
	private function checkIsPaid($ct_api_key = '' , $force_check = false)
	{		
		$config = $this->getCTConfig();	
		$api_key = trim($ct_api_key);	


		if($config['acc_status_last_check'] < time() - $config['acc_status_check_interval'] || $force_check)
		{				
			$result = CleantalkHelper::api_method__notice_paid_till($api_key);
			$save_params = array();
			
			$save_params['connection_reports'] = $config['connection_reports'];
		    $save_params['acc_status_last_check'] = time();	

			if (empty($result['error']))
			{
				$save_params['ct_key_is_ok'] = 1;

				$save_params['show_notice'] = isset($result['show_notice']) ? $result['show_notice'] : 0;
				$save_params['renew'] = isset($result['renew']) ? $result['renew'] : 0;
				$save_params['trial'] = isset($result['trial']) ? $result['trial'] : 0;
		    	$save_params['user_token'] = isset($result['user_token']) ? $result['user_token'] : '';
		    	$save_params['spam_count'] = isset($result['spam_count']) ? $result['spam_count'] : 0;
		    	$save_params['moderate_ip'] = isset($result['moderate_ip']) ? $result['moderate_ip'] : 0;
		    	$save_params['moderate'] = isset($result['moderate']) ? $result['moderate'] : 0;				    	
		    	$save_params['show_review'] = isset($result['show_review']) ? $result['show_review'] : 0; 
		    	$save_params['service_id'] = isset($result['service_id']) ? $result['service_id'] : '';
		    	$save_params['license_trial'] = isset($result['license_trial']) ? $result['license_trial'] : 0;
		    	$save_params['valid'] = isset($result['valid']) ? $result['valid'] : 0;		
		    	$save_params['auto_update_app'] = isset($result['auto_update_app']) ? $result['auto_update_app'] : 0;	
		    	$save_params['show_auto_update_notice'] = isset($result['show_auto_update_notice']) ? $result['show_auto_update_notice'] : 0;			    	 
		    	$save_params['ip_license'] = isset($result['ip_license']) ? $result['ip_license'] : 0;		

			}
			else $save_params['ct_key_is_ok'] = 0;				
		}	
		
		return isset($save_params) ? $save_params : null;				
    }
    
    /*
	* Get data from submit recursively
	*/
	
	private function getFieldsAny($arr, $message=array(), $email = null, $nickname = array('nick' => '', 'first' => '', 'last' => ''), $subject = null, $contact = true, $prev_name = '')
	{

		//Skip request if fields exists
		$skip_params = array(
		    'ipn_track_id', 	// PayPal IPN #
		    'txn_type', 		// PayPal transaction type
		    'payment_status', 	// PayPal payment status
		    'ccbill_ipn', 		// CCBill IPN 
			'ct_checkjs', 		// skip ct_checkjs field
			'api_mode',         // DigiStore-API
			'loadLastCommentId', // Plugin: WP Discuz. ticket_id=5571
		);

		// Fields to replace with ****
		$obfuscate_params = array(
		    'password',
		    'pass',
		    'pwd',
			'pswd'
		);

		// Skip feilds with these strings and known service fields
		$skip_fields_with_strings = array( 
			// Common
			'ct_checkjs', //Do not send ct_checkjs
			'nonce', //nonce for strings such as 'rsvp_nonce_name'
			'security',
			// 'action',
			'http_referer',
			'timestamp',
			'captcha',
			// Formidable Form
			'form_key',
			'submit_entry',
			// Custom Contact Forms
			'form_id',
			'ccf_form',
			'form_page',
			// Qu Forms
			'iphorm_uid',
			'form_url',
			'post_id',
			'iphorm_ajax',
			'iphorm_id',
			// Fast SecureContact Froms
			'fs_postonce_1',
			'fscf_submitted',
			'mailto_id',
			'si_contact_action',
			// Ninja Forms
			'formData_id',
			'formData_settings',
			'formData_fields_\d+_id',
			'formData_fields_\d+_files.*',		
			// E_signature
			'recipient_signature',
			'output_\d+_\w{0,2}',
			// Contact Form by Web-Settler protection
		    '_formId',
		    '_returnLink',
			// Social login and more
			'_save',
			'_facebook',
			'_social',
			'user_login-',
			// Contact Form 7
			'_wpcf7',
			'avatar__file_image_data',
		);
		$fields_exclusions = CleantalkCustomConfig::get_fields_exclusions();
		if ($fields_exclusions)
		    array_merge($skip_fields_with_strings,$fields_exclusions); 	
		// Reset $message if we have a sign-up data
		$skip_message_post = array(
		    'edd_action', // Easy Digital Downloads
		);

			foreach($skip_params as $value){
				if(@array_key_exists($value,$_GET)||@array_key_exists($value,$_POST))
					$contact = false;
			} unset($value);
			
		if(count($arr)){
			foreach($arr as $key => $value){
				
				if(gettype($value)=='string'){
					$decoded_json_value = json_decode($value, true);
					if($decoded_json_value !== null)
						$value = $decoded_json_value;
				}
				
				if(!is_array($value) && !is_object($value)){
					
					if (in_array($key, $skip_params, true) && $key != 0 && $key != '' || preg_match("/^ct_checkjs/", $key))
						$contact = false;
					
					if($value === '')
						continue;
					
					// Skipping fields names with strings from (array)skip_fields_with_strings
					foreach($skip_fields_with_strings as $needle){
						if (preg_match("/".$needle."/", $prev_name.$key) == 1){
							continue(2);
						}
					}unset($needle);
					
					// Obfuscating params
					foreach($obfuscate_params as $needle){
						if (strpos($key, $needle) !== false){
							$value = $this->obfuscate_param($value);
							continue(2);
						}
					}unset($needle);
					

					// Decodes URL-encoded data to string.
					$value = urldecode($value);	

					// Email
					if (!$email && preg_match("/^\S+@\S+\.\S+$/", $value)){
						$email = $value;
						
					// Names
					}elseif (preg_match("/name/i", $key)){
						
						preg_match("/((name.?)?(your|first|for)(.?name)?)$/", $key, $match_forename);
						preg_match("/((name.?)?(last|family|second|sur)(.?name)?)$/", $key, $match_surname);
						preg_match("/^(name.?)?(nick|user)(.?name)?$/", $key, $match_nickname);
						
						if(count($match_forename) > 1)
							$nickname['first'] = $value;
						elseif(count($match_surname) > 1)
							$nickname['last'] = $value;
						elseif(count($match_nickname) > 1)
							$nickname['nick'] = $value;
						else
							$message[$prev_name.$key] = $value;
					
					// Subject
					}elseif ($subject === null && preg_match("/subject/i", $key)){
						$subject = $value;
					
					// Message
					}else{
						$message[$prev_name.$key] = $value;					
					}
					
				}elseif(!is_object($value)){
					
					$prev_name_original = $prev_name;
					$prev_name = ($prev_name === '' ? $key.'_' : $prev_name.$key.'_');
					
					$temp = $this->getFieldsAny($value, $message, $email, $nickname, $subject, $contact, $prev_name);
					
					$message 	= $temp['message'];
					$email 		= ($temp['email'] 		? $temp['email'] : null);
					$nickname 	= ($temp['nickname'] 	? $temp['nickname'] : null);				
					$subject 	= ($temp['subject'] 	? $temp['subject'] : null);
					if($contact === true)
						$contact = ($temp['contact'] === false ? false : true);
					$prev_name 	= $prev_name_original;
				}
			} unset($key, $value);
		}

		foreach ($skip_message_post as $v) {
		    if (isset($_POST[$v])) {
		        $message = null;
		        break;
		    }
		} unset($v);

		//If top iteration, returns compiled name field. Example: "Nickname Firtsname Lastname".
		if($prev_name === ''){
			if(!empty($nickname)){
				$nickname_str = '';
				foreach($nickname as $value){
					$nickname_str .= ($value ? $value." " : "");
				}unset($value);
			}
			$nickname = $nickname_str;
		}

		$return_param = array(
			'email' 	=> $email,
			'nickname' 	=> $nickname,
			'subject' 	=> $subject,
			'contact' 	=> $contact,
			'message' 	=> $message
		);	
		return $return_param;
	}
	
	/**
	* Masks a value with asterisks (*) Needed by the getFieldsAny()
	* @return string
	*/
	private function obfuscate_param($value = null) {
		if ($value && (!is_object($value) || !is_array($value))) {
			$length = strlen($value);
			$value = str_repeat('*', $length);
		}

		return $value;
	}	
    /**
     * This event is triggered after Joomla initialization
     * Joomla 1.5
     * @access public
     */
    
    public function onAfterInitialise()
    {
		
		$config = $this->getCTConfig();
        $app = JFactory::getApplication(); 

		if($app->isAdmin() && $app->input->get('layout') == 'edit' && $app->input->get('extension_id') == $this->_id)
		{
			$output = null;
			$save_params = array();
			
			// Close review banner
			if(isset($_POST['ct_delete_notice'])&&$_POST['ct_delete_notice']==='yes')
				$save_params['show_review_done'] = 1;
			
			// Getting key automatically
			if(isset($_POST['get_auto_key']) && $_POST['get_auto_key'] === 'yes'){

				$output = CleantalkHelper::api_method__get_api_key(JFactory::getConfig()->get('mailfrom'), $_SERVER['HTTP_HOST'], 'joomla3');
				// Checks if the user token is empty, then get user token by notice_paid_till()
				if(empty($output['user_token'])){				
					$result_tmp = CleantalkHelper::api_method__notice_paid_till($output['auth_key']);
					$output['user_token'] = $result_tmp['user_token'];				
				}
					
			}
			
			// Check spam users
			if (isset($_POST['check_type']) && $_POST['check_type'] === 'users')
			{
				$improved_check = ($_POST['improved_check'] == 'true')?true:false;
				$offset = isset($_POST['offset'])?$_POST['offset']:0;
				$on_page = isset($_POST['amount'])?$_POST['amount']:2;
				$output = self::get_spam_users($offset,$on_page,$improved_check);
			}
			// Check spam comments
			if (isset($_POST['check_type']) && $_POST['check_type'] === 'comments')
			{
				$improved_check = ($_POST['improved_check'] == 'true')?true:false;
				$offset = isset($_POST['offset'])?$_POST['offset']:0;
				$on_page = isset($_POST['amount'])?$_POST['amount']:2;
				$output = self::get_spam_comments($offset,$on_page,$improved_check);
			}
			if (isset($_POST['ct_del_user_ids']))
			{
				$spam_users = implode(',',$_POST['ct_del_user_ids']);
				$output['result']=null;
				$output['data']=null;
				try {
					$this->delete_users($spam_users);
					$output['result']='success';
					$output['data']=JText::sprintf('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_USERS_DELDONE', count($_POST['ct_del_user_ids']));
				}
				catch (Exception $e){
					$output['result']='error';
					$output['data']=$e->getMessage();
				}
			}
			if (isset($_POST['ct_del_comment_ids']))
			{
				$spam_comments = implode(',',$_POST['ct_del_comment_ids']);
				$output['result']=null;
				$output['data']=null;
				try {
					$this->delete_comments($spam_comments);
					$output['result']='success';
					$output['data']=JText::sprintf('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_COMMENTS_DELDONE', count($_POST['ct_del_comment_ids']));
				}
				catch (Exception $e){
					$output['result']='error';
					$output['data']=$e->getMessage();
				}		
			}
			if (isset($_POST['send_connection_report']) && $_POST['send_connection_report'] === 'yes')
			{
				$output['result']=null;
				$output['data']=null;
				if ($config['connection_reports']['negative_report'] !== null)
				{
					$to  = "welcome@cleantalk.org" ; 
					$subject = "Connection report for ".$_SERVER['HTTP_HOST']; 
					$message = ' 
					<html> 
						<head> 
							<title></title> 
						</head> 
						<body> 
							<p>From '.date('d M',$config['connection_reports']['negative_report'][0]->date).' to '.date('d M').' has been made '.($config['connection_reports']['success']+$config['connection_reports']['negative']).' calls, where '.$config['connection_reports']['success'].' were success and '.$config['connection_reports']['negative'].' were negative</p> 
							<p>Negative report:</p>
							<table>  <tr>
						<td>&nbsp;</td>
						<td><b>Date</b></td>
						<td><b>Page URL</b></td>
						<td><b>Library report</b></td>
					  </tr>
					';
				}
				foreach ($config['connection_reports']['negative_report'] as $key=>$report)
				{
					$message.= "<tr><td>".($key+1).".</td><td>".$report->date."</td><td>".$report->page_url."</td><td>".$report->lib_report."</td></tr>";
				}  
				$message.='</table></body></html>'; 

				$headers  = "Content-type: text/html; charset=windows-1251 \r\n";
				$headers .= "From: ".JFactory::getConfig()->get('mailfrom'); 
				mail($to, $subject, $message, $headers);   			
				$output['result']='success';
				$output['data']='Success.';
				$save_params['connection_reports'] = array('success' => 0, 'negative'=> 0,'negative_report' => null);
			}

			if (isset($_POST['dev_insert_spam_users']) && $_POST['dev_insert_spam_users'] === 'yes')
				$output = self::dev_insert_spam_users();

			$this->saveCTConfig($save_params);

			if ($output !== null)
			{
				print json_encode($output);
				$mainframe=JFactory::getApplication();
				$mainframe->close();
				die();
			}
		}
    }

    //Delete spam users
	private function delete_users($user_ids)
	{
		if (isset($user_ids))
		{
		 	$db = JFactory::getDBO();
		    $db->setQuery("DELETE FROM `#__users` WHERE id IN (".$user_ids.")");
		    $result = $db->execute();
		    $db->setQuery("DELETE FROM `#__user_usergroup_map` WHERE user_id IN (".$user_ids.")"); 	
		    $result=$db->execute();	
		    $db->setQuery("SHOW TABLES LIKE '#__jcomments'");
			$jtable = $db->loadAssocList();
			if (!empty($jtable))
			{
		 	    $db->setQuery("DELETE FROM `#__jcomments` WHERE userid IN (".$user_ids.")"); 	
			    $result=$db->execute();					
			}

		}
	}

	//Delete spam comments
	private function delete_comments($comment_ids)
	{
		if (isset($comment_ids))
		{
		 	$db = JFactory::getDBO();
		    $db->setQuery("DELETE FROM `#__jcomments` WHERE id IN (".$comment_ids.")");
		    $result = $db->execute();
		}

	}     
	/**
	 * Event triggered after update an extension
	 *
	 * @param   JInstaller $installer   Installer instance
	 * @param   int        $extensionId Extension Id
	 *
	 * @return void
	 */    
    public function onExtensionAfterUpdate($installer, $extensionId)
    {
		$config = $this->getCTConfig();

		//Sending agent version	
		if(isset($config['apikey']) && $config['apikey'] !== '')
			CleantalkHelper::api_method_send_empty_feedback($config['apikey'], self::ENGINE);
    }
    /**
     * This event is triggered after extension save their settings
     * Joomla 2.5+
     * @access public
     */        
	public function onExtensionAfterSave($name, $data)
	{
        $app = JFactory::getApplication();

		if ($app->input->get('layout') == 'edit' && $app->input->get('extension_id') == $this->_id)
		{
			if ($data->enabled)
			{
				$new_config=json_decode($data->params,true);	
				$access_key = trim($new_config['apikey']);

				if (isset($new_config['sfw_enable']) && $new_config['sfw_enable'] == 1 && $access_key != '')
				{
					$sfw = new CleantalkSFW();
					$sfw->sfw_update($access_key);
					$sfw->send_logs($access_key);
				}
				CleantalkHelper::api_method_send_empty_feedback($access_key, self::ENGINE);

		        $this->saveCTConfig($this->checkIsPaid($access_key,true));

			}
		}		
	}
    /*
    exception for MijoShop ajax calls
    */
    public function exceptionList()
    {
        $option_cmd = JFactory::getApplication()->input->get('option');
        $task_cmd = JFactory::getApplication()->input->get('task');		

    	if( (@$_GET['option']=='com_mijoshop' && @$_GET['route']=='api/customer') ||
    		($option_cmd == 'com_virtuemart' && $task_cmd == 'add') ||
    		$option_cmd == 'com_jcomments' ||
    		$option_cmd == 'com_contact'  ||
    		$option_cmd == 'com_users'    ||
    		$option_cmd == 'com_user'     ||
    		$option_cmd == 'com_login'    ||
    		$option_cmd == 'com_akeebasubs' ||
    		$option_cmd == 'com_acymailing' ||
    		$option_cmd == 'com_easybookreloaded' ||
    		$option_cmd == 'com_easysocial')
    		return true;

    	return false;
    	
    }

    /**
     * This event is triggered before an update of a user record.
     * @access public
     */
    public function onUserBeforeSave($user, $isnew, $new){

        if ($isnew)
            $this->moderateUser();

        return null;
    }

    /**
     * This event is triggered before an update of a user record.
     * Joomla 1.5
     * @access public
     */
    public function onBeforeStoreUser($user, $isnew){
        if ($isnew)
            $this->moderateUser();

        return null;
    }
    
    public function onAfterRender(){
		
    	$config = $this->getCTConfig();	

    	if($config['tell_about_cleantalk'] == 1 && strpos($_SERVER['REQUEST_URI'],'/administrator/') === false){
    		if ($config['spam_count'] > 0)
				$code = "<div id='cleantalk_footer_link' style='width:100%;text-align:center;'><a href='https://cleantalk.org/joomla-anti-spam-plugin-without-captcha'>Anti-spam by CleanTalk</a> for Joomla!<br>".$config['spam_count']." spam blocked</div>";
			else
				$code = "<div id='cleantalk_footer_link' style='width:100%;text-align:center;'><a href='https://cleantalk.org/joomla-anti-spam-plugin-without-captcha'>Anti-spam by CleanTalk</a> for Joomla!<br></div>";

			if(version_compare(JVERSION, '3.0', '<') == 1)
			{
				$documentbody = JResponse::getBody();
				$documentbody = str_replace ("</body>", $code." </body>", $documentbody);
				JResponse::setBody($documentbody);
			}
			else
			{
				$documentbody = JFactory::getApplication()->getBody();
				$documentbody = str_replace ("</footer>", $code." </footer>", $documentbody);
				JFactory::getApplication()->setBody($documentbody);	

			}
		}

    }
    
    /**
     * Save user registration request_id
     * @access public
     * @return type
     */
    public function onBeforeCompileHead()
    {
    	$config = $this->getCTConfig();
    	$user = JFactory::getUser();
		$app = JFactory::getApplication();	
		$document = JFactory::getDocument();

		// Version comparsion
		if(!version_compare(JVERSION, '3', 'ge'))
		{			
			$buf=$document->getHeadData();
			$is_jquery=false;
			foreach($buf['scripts'] as $key=>$value )				
				if(stripos($key,'jquery')!==false)
					$is_jquery=true;				
			if(!$is_jquery)
				$document->addScript(Juri::root()."plugins/system/antispambycleantalk/jquery-1.11.2.min.js");
			
			$document->addScriptDeclaration("jQuery.noConflict();");
			$document->addScriptDeclaration("var ct_joom25=true;");
			
		}
		else
		{
			JHtml::_('jquery.framework');
			$document->addScriptDeclaration("var ct_joom25=false;");
		}	

		if ($app->isSite())
		{			
	        $this->sfw_check();			
			$this->ct_cookie();	
			$document->addScriptDeclaration($this->getJSTest());
			if ($config['check_external'])
				$document->addScript(JURI::root(true)."/plugins/system/antispambycleantalk/js/ct-external.js?".time());
		}

    	if($user->get('isRoot'))
    	{			
			if($app->isAdmin())
			{
				$temp_config = $this->checkIsPaid($config['apikey']);

				if ($temp_config)
				{
					$this->saveCTConfig($temp_config);
					$config = array_merge($config,$temp_config);
				}

				if (!$config['ct_key_is_ok'])
					$notice = JText::_('PLG_SYSTEM_CLEANTALK_NOTICE_APIKEY');

				if ($config['show_notice'] == 1 && $config['trial'] == 1)
					$notice = JText::sprintf('PLG_SYSTEM_CLEANTALK_NOTICE_TRIAL', $config['user_token']);

				$adminmail=JFactory::getConfig()->get('mailfrom');
				// Passing parameters to JS
				$document->addScriptDeclaration('
					//Control params
					var ct_key_is_ok = "'.$config['ct_key_is_ok'].'",
						cleantalk_domain="'.$_SERVER['HTTP_HOST'].'",
						cleantalk_mail="'.$adminmail.'",
						ct_ip_license = "'.$config['ip_license'].'",
						ct_moderate_ip = "'.$config['moderate_ip'].'",
						ct_user_token="'.$config['user_token'].'",
						ct_service_id="'.$config['service_id'].'",
						ct_connection_reports_success ="'.$config['connection_reports']['success'].'",
						ct_connection_reports_negative ="'.$config['connection_reports']['negative'].'",
						ct_connection_reports_negative_report = "'.addslashes(json_encode($config['connection_reports']['negative_report'])).'",
						ct_notice_review_done ='.((isset($config['show_review_done']) && $config['show_review_done'] === 1)?'true':'false').';
					
					//Translation
					var ct_autokey_label = "'    .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_AUTOKEY_LABEL').'",
						ct_manualkey_label = "'  .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_MANUALKEY_LABEL').'",
						ct_key_notice1 = "'      .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_NOTICE1').'",
						ct_key_notice2 = "'      .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_NOTICE2').'",
						ct_license_notice = "'   .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_LICENSE_NOTICE').'",
						ct_statlink_label = "'   .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_STATLINK_LABEL').'",
						ct_impspamcheck_label = "'   .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_IMPSPAMCHECK_LABEL').'",
						ct_supportbtn_label = "'   .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SUPPORTBTN_LABEL').'",
						ct_register_message="'   .JText::_('PLG_SYSTEM_CLEANTALK_REGISTER_MESSAGE').$adminmail.'",
						ct_key_is_bad_notice = "' .JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_KEY_IS_BAD').'",
						ct_register_error="'.addslashes(JText::_('PLG_SYSTEM_CLEANTALK_ERROR_AUTO_GET_KEY')).'",
						ct_spamcheck_checksusers = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_CHECKUSERS_LABEL').'",
						ct_spamcheck_checkscomments = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_CHECKCOMMENTS_LABEL').'",
						ct_spamcheck_notice = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOTICE').'",
						ct_spamcheck_delsel = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_DELSEL').'",
						ct_spamcheck_delall = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_DELALL').'",
						ct_spamcheck_table_username = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_TABLE_USERNAME').'",
						ct_spamcheck_table_joined = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_TABLE_JOINED').'",
						ct_spamcheck_table_email = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_TABLE_EMAIL').'",
						ct_spamcheck_table_lastvisit = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_TABLE_LASTVISIT').'",
						ct_spamcheck_table_date = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_TABLE_DATE').'",
						ct_spamcheck_table_text = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_TABLE_TEXT').'",
						ct_spamcheck_users_delconfirm = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_USERS_DELCONFIRM').'",
						ct_spamcheck_users_delconfirm_error = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_USERS_DELCONFIRM_ERROR').'",
						ct_spamcheck_comments_delconfirm = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_COMMENTS_DELCONFIRM').'",
						ct_spamcheck_comments_delconfirm_error = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_COMMENTS_DELCONFIRM_ERROR').'",
						ct_spamcheck_load_more_results = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_LOAD_MORE_RESULTS').'",
						ct_connection_reports_no_reports = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_CONNECTIONREPORTS_NO_REPORTS').'",
						ct_connection_reports_send_report = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_CONNECTIONREPORTS_SENDBUTTON_LABEL').'",
						ct_connection_reports_table_date = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_CONNECTIONREPORTS_TABLE_DATE').'",
						ct_connection_reports_table_pageurl = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_CONNECTIONREPORTS_TABLE_PAGEURL').'",
						ct_connection_reports_table_libreport = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_CONNECTIONREPORTS_TABLE_LIBREPORT').'";																
				');
				//Admin JS and CSS
				$document->addScript(JURI::root(true)."/plugins/system/antispambycleantalk/js/ct-settings.js?".time());
				$document->addStyleSheet(JURI::root(true)."/plugins/system/antispambycleantalk/css/ct-settings.css?".time());
				
				if($config['show_review'] == 1 && $app->input->get('layout') == 'edit' && $app->input->get('extension_id') == $this->_id)
				{
					$document->addScriptDeclaration('var ct_show_feedback=true;');
					$document->addScriptDeclaration('var ct_show_feedback_mes="'.JText::_('PLG_SYSTEM_CLEANTALK_FEEDBACKLINK').'";');
				}
				else
					$document->addScriptDeclaration('var ct_show_feedback=false;');	
														
			}
			if(isset($notice))
				JFactory::getApplication()->enqueueMessage($notice,'notice');
		}

    }

    /**
     * onAfterRoute trigger - used by com_contact
     * @access public
     * @since 1.5
     */
    public function onAfterRoute() 
    {
        $app = JFactory::getApplication();
        if ($app->isAdmin())
        	return;

        $option_cmd = $app->input->get('option');
        $view_cmd = $app->input->get('view');
        $task_cmd = $app->input->get('task');
        $page_cmd = $app->input->get('page');        
        $config = $this->getCTConfig();
        $ver = new JVersion();

        // constants can be found in  components/com_contact/views/contact/tmpl/default_form.php
        // 'option' and 'view' constants are the same in all versions
        if (strcmp($ver->RELEASE, '1.5') <= 0) {
            if ($option_cmd == 'com_user') {
                if ($task_cmd == 'register_save') {
                } else {
                    $document = & JFactory::getDocument();
                    $document->addScriptDeclaration($this->fillRegisterFormScriptHTML('josForm'));
                }
            }
            if ($option_cmd == 'com_virtuemart') {
                if ($task_cmd == 'registercartuser' 
                    || $task_cmd == 'registercheckoutuser'
                    || $task_cmd == 'saveUser' 
                    || $page_cmd == 'shop.registration'
                    || $page_cmd == 'checkout.index'
                    ) {
                } else {
                    $document = & JFactory::getDocument();
                    $document->addScriptDeclaration($this->fillRegisterFormScriptHTML('userForm'));
                }
            }

        } else {
            //com_users - registration - registration.register
            if ($option_cmd == 'com_users') {
                if ($task_cmd == 'registration.register') {
                } else {
                    $document = JFactory::getDocument();
                    $document->addScriptDeclaration($this->fillRegisterFormScriptHTML('member-registration'));
                }
            }
           if ($option_cmd == 'com_virtuemart') {
                if ($task_cmd == 'editaddresscart') {
                    $document = JFactory::getDocument();
                    $document->addScriptDeclaration($this->fillRegisterFormScriptHTML('userForm'));
                } elseif ($task_cmd == 'registercartuser' 
                    || $task_cmd == 'registercheckoutuser' 
                    || $task_cmd == 'checkout' // OPC
                    ) {
                     $this->moderateUser();
                } 

            }
        }
        if ($_SERVER['REQUEST_METHOD'] == 'POST')
        {
        	$this->ct_direct_post = 1;

        	/*
	            Contact forms anti-spam code
	        */
	        $sender_email = null;
	        $message = '';
	        $sender_nickname = null;
			$post_info = array(
				'comment_type' => 'feedback_general_contact_form',
				'post_url'     => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : ''
			);     
			if ($option_cmd == 'com_rsform')
				$post_info['comment_type'] = 'rsform_contact_form';
	        //Rapid
	        if (isset($_POST['rp_email'])){ 
	            $sender_email = $_POST['rp_email'];

	            if (isset($_POST["rp_subject"]))
	                $message = $_POST["rp_subject"];
	            
	            if (isset($_POST['rp_message']))
	                $message .= ' ' . $_POST['rp_message'];
	            $post_info['comment_type'] = 'rapid_contact_form';
	        } //VTEM Contact
	        elseif (isset($_POST["vcontact_email"])) { 
	            $sender_email = $_POST['vcontact_email'];
	            if (isset($_POST["vcontact_subject"]))
	                $message = $_POST["vcontact_subject"];

	            if (isset($_POST["vcontact_message"]))
	                $message .= ' ' . $_POST["vcontact_message"];
	            
	            if (isset($_POST["vcontact_name"]))
	                $sender_nickname = $_POST["vcontact_name"];
	            $post_info['comment_type'] = 'vtem_contact_form';
	        } //BreezingForms
	        elseif (isset($_POST['ff_task']) && $_POST['ff_task'] == 'submit') {

	            foreach ($_POST as $v) {
	                if (is_array($v)) {
	                    foreach ($v as $k=>$v2) {
	                        if ($this->validEmail($v2)) {
	                            $sender_email = $v2;
	                        }
	                        else
	                        {
	                        	if(is_int($k))
	                        	{
	                        		$message.=$v2."\n";
	                        	}
	                        }
	                    }
	                } else {
	                    if ($this->validEmail($v)) {
	                        $sender_email = $v;
	                    }
	                    else
	                    {
	                   		//$contact_message.=$v."\n";
	                    }
	                }
	            }
	            $post_info['comment_type'] = 'breezing_contact_form';
	        }
	        // Genertal test for any forms or form with custom fields
	        elseif ($config['general_contact_forms_test'] ||
	        	$config['check_external'] || 
	        	$option_cmd == 'com_rsform' ||
	        	$option_cmd == 'com_virtuemart')
	        {
				$ct_temp_msg_data = $this->getFieldsAny($_POST);
				$sender_email    = ($ct_temp_msg_data['email']    ? $ct_temp_msg_data['email']    : '');
				$sender_nickname = ($ct_temp_msg_data['nickname'] ? $ct_temp_msg_data['nickname'] : '');
				$subject         = ($ct_temp_msg_data['subject']  ? $ct_temp_msg_data['subject']  : '');
				$contact_form    = ($ct_temp_msg_data['contact']  ? $ct_temp_msg_data['contact']  : true);
				$message         = ($ct_temp_msg_data['message']  ? $ct_temp_msg_data['message']  : array());

				if ($subject != '')
					$message = array_merge(array('subject' => $subject), $message);
				$message = implode("\n", $message);
	        }

	        if (!$this->exceptionList() && (trim($sender_email) !='' || $config['check_all_post']))
	        {
	        	$ctResponse = self::ctSendRequest(
		            'check_message', array(
		                'sender_nickname' => $sender_nickname,
		                'sender_email' => $sender_email,
		                'message' => trim(preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/","\n", $message)),
		                'post_info' => $post_info,
		            )
	        	);

	            if ($ctResponse) 
	            {
			        if (!empty($ctResponse) && is_array($ctResponse)) 
			        {
			            if ($ctResponse['errno'] != 0) 
			                $this->sendAdminEmail("CleanTalk. Can't verify feedback message!", $ctResponse['comment']);
						else 
						{
			                if ($ctResponse['allow'] == 0)
			                {
			            		$error_tpl=file_get_contents(dirname(__FILE__)."/error.html");
								print str_replace('%ERROR_TEXT%',$ctResponse['comment'],$error_tpl);
								die();		                    	                	
			                } 

			            }
			        } 
	            }
	        }        	
        } 
            

    }

    ////////////////////////////
    // com_contact related sutff

    /**
     * onValidateContact trigger - used by com_contact
     * @access public
     * @param &$contact
     * @param &$data
     * @return instanceof Exception when fails
     * @since 1.5
     */
    public function onValidateContact(&$contact, &$data) {
    	
        $session = JFactory::getSession();

        $ver = new JVersion();
        // constants can be found in components/com_contact/views/contact/tmpl/default_form.php
        if (strcmp($ver->RELEASE, '1.5') <= 0) {  // 1.5 and lower
            $user_name_key = 'name';
            $user_email_key = 'email';
            $subject_key = 'subject';
            $message_key = 'text';
            $sendAlarm = TRUE;
        } else {      // current higest version by default ('2.5' now)
            $user_name_key = 'contact_name';
            $user_email_key = 'contact_email';
            $subject_key = 'contact_subject';
            $message_key = 'contact_message';
        }
        
        $post_info['comment_type'] = 'feedback';
        $post_info = json_encode($post_info);
        if ($post_info === false)
            $post_info = '';

        $ctResponse = self::ctSendRequest(
            'check_message', array(
                'sender_nickname' => $data[$user_name_key],
                'sender_email' => $data[$user_email_key],
                'message' => $data[$subject_key] . "\n " . $data[$message_key],
                'post_info' => $post_info,
            )
        );
        if ($ctResponse)
        {
	        $app = JFactory::getApplication();
	        if (!empty($ctResponse) && is_array($ctResponse)) {
	            if ($ctResponse['errno'] != 0) {
	                $this->sendAdminEmail("CleanTalk. Can't verify feedback message!", $ctResponse['comment']);
	            } else {
	                if ($ctResponse['allow'] == 0) {
	                    $res_str = $ctResponse['comment'];
	                    $app->setUserState('com_contact.contact.data', $data);  // not used in 1.5 :(
	                    $stub = JFactory::getApplication()->input->get('id');
	                    // Redirect back to the contact form.
	                    // see http://docs.joomla.org/JApplication::redirect/11.1 - what does last param mean?
	                    // but it works! AZ
	                    $app->redirect(JRoute::_('index.php?option=com_contact&view=contact&id=' . $stub, false), $res_str, 'warning');
	                    return new Exception($res_str); // $res_str not used in com_contact code - see source :(
	                }
	            }
	        }        	
        }

    }

    ////////////////////////////
    // JComments related sutff

    /* List of available triggers in JComments 2.3.0 - jcomments.ajax.php

      onJCommentsCaptchaVerify
      onJCommentsCommentBeforeAdd	- used, working
      onJCommentsCommentAfterAdd
      onJCommentsCommentBeforeDelete
      onJCommentsCommentAfterDelete	- used, but not called from comments admin panel
      onJCommentsCommentBeforePublish - used, working
      onJCommentsCommentAfterPublish
      onJCommentsCommentBeforeChange
      onJCommentsCommentAfterChange
      onJCommentsCommentBeforeVote
      onJCommentsCommentAfterVote
      onJCommentsCommentBeforeReport
      onJCommentsCommentAfterReport
      onJCommentsUserBeforeBan
      onJCommentsUserAfterBan

     */
    
    /**
     * onJCommentsFormAfterDisplay trigger
     * @access public
     * @return string html code to insert after JComments form (id="comments-form")
     * @since 1.5
     */
    public function onJCommentsFormAfterDisplay() {
        $this->JCReady = true;
        return null; 
    }

    /**
     * onJCommentsCommentBeforeAdd trigger
     * @access public
     * @param JCommentsDB $comment
     * @return bolean true
     * @since 1.5
     */
    public function onJCommentsCommentBeforeAdd(&$comment) {
        
        $config = $this->getCTConfig();
        
        $session = JFactory::getSession();

        // set new time because onJCommentsFormAfterDisplay worked only once
        // and formtime in session need to be renewed between ajax posts
        
        $post_info['comment_type'] = 'comment'; 
        $post_info['post_url'] = $session->get('cleantalk_current_page'); 
        $post_info = json_encode($post_info);
        if ($post_info === false) {
            $post_info = '';
        }
        
        $plugin_groups = array();
        $param_groups = $this->params->get('groups');
        if (is_array($param_groups)) {
            foreach ($param_groups as $group) {
                array_push($plugin_groups, (int) $group);
            }
        } else {
            array_push($plugin_groups, (int) $param_groups);
        }

        $user = JFactory::getUser();
        if (method_exists($user, 'getAuthorisedGroups')) {    // 1.6+
            $user_groups = $user->getAuthorisedGroups();
        } else {                                              // 1.5
            $user_groups = array();
            if ($user->guest) {
                array_push($user_groups, 29);
            } else {
                array_push($user_groups, $user->gid);
            }
        }

        foreach ($user_groups as $group) {
            if (in_array($group, $plugin_groups)) {
                
                $example = null;
                if ($config['relevance_test'] !== '') {
                    switch ($comment->object_group) {
                        case 'com_content':
                            $article = JTable::getInstance('content');
                            $article->load($comment->object_id);
                            $baseText = $article->introtext . '<br>' . $article->fulltext;
                            break;
                        default:
                            $baseText = '';
                    }

                    $db = JCommentsFactory::getDBO();
                    $query = "SELECT comment "
                            . "\nFROM #__jcomments "
                            . "\nWHERE published = 1 "
                            . "\n  AND object_group = '" . $db->getEscaped($comment->object_group) . "'"
                            . "\n  AND object_id = " . $comment->object_id
                            . (JCommentsMultilingual::isEnabled() ? "\nAND lang = '" . JCommentsMultilingual::getLanguage() . "'" : "")
                            . " ORDER BY id DESC "
                            . " LIMIT 10 "
                    ;
                    $db->setQuery($query);
                    $prevComments = $db->loadResultArray();
                    $prevComments = $prevComments == NULL ? '' : implode("\n\n", $prevComments);
                    
                    $example = $baseText . "\n\n\n\n" . $prevComments;
                }

                $ctResponse = self::ctSendRequest(
                    'check_message', array(
                        'example' => $example,
                        'message' =>preg_replace('/\s+/', ' ',str_replace("<br />", " ", $comment->comment)),
                        'sender_nickname' => $comment->name,
                        'sender_email' => $comment->email,
                        'post_info' => $post_info,
                    )
                );
                if ($ctResponse)
                {
					if (!empty($ctResponse) && is_array($ctResponse)) {
						if ($ctResponse['allow'] == 0) {
						    if ($config['jcomments_unpublished_nofications'] != '') {
						        JComments::sendNotification($comment, true);
						    }
							if ($ctResponse['stop_queue'] === 1)
							{
						     	JCommentsAJAX::showErrorMessage($ctResponse['comment'], 'comment');
						    	return false;                 		
							}
							$comment->published = false;  

						}
					}
               	
                }
                return true;
            } //if(in_array($group, $plugin_groups))
        } //foreach
    }

    ////////////////////////////
    // Private methods

    /**
     * Include in head adn fill form
     * @param type $form_id
     * @param type $data
     * @return string
     */
    private function fillRegisterFormScriptHTML($form_id, $data = null, $onLoad = true) {
        if ($data === null) {
            $session = JFactory::getSession();
            $data = $session->get('ct_register_form_data');
        }
       
        $str = "\n";
        
        // setTimeout to fill form under Joomla 1.5
        $str .= 'window.onload = window.setTimeout(\'fillHide()\', 1000); function fillHide() {';

        $str .= 'form = document.getElementById("' . $form_id . '");' . "\n";
        $str .= 'if(form){' . "\n";
        if (!empty($data)) {
            foreach ($data as $key => $val) {
                
                // Skip data for JavaScript test
                if (preg_match('/^ct_checkjs/', $key))
                    continue;

                if (is_array($val)) {
                    foreach ($val as $_key => $_val) {
                        if (is_array($_val)) {
                            continue;
                        }

                        $str .= "\t" . 'if (document.getElementsByName("' . $key . '[' . $_key . ']")) {' . "\n";
                        $str .= "\t\t" . 'if (document.getElementsByName("' . $key . '[' . $_key . ']")[0].type != "hidden") {' . "\n";
                        $str .= "\t\t\t" . 'document.getElementsByName("' . $key . '[' . $_key . ']")[0].value = "' . $_val . '"' . "\n";
                        $str .= "\t\t } \n";
                        $str .= "\t } \n";
                    }
                } else {
                    $str .= "\t" . 'if (document.getElementsByName("' . $key . '")) {' . "\n";
                    $str .= "\t\t" . 'if (document.getElementsByName("' . $key . '")[0].type != "hidden") {' . "\n";
                    $str .= "\t\t\t" . 'document.getElementsByName("' . $key . '")[0].value = "' . $val . '"' . "\n";
                    $str .= "\t\t } \n";
                    $str .= "\t } \n";
                }
            }
        }
        $str .= '}' . "\n";
        $str .= '}' . "\n";

        return $str;
    }


    /**
     * Moderate new user
     */
    private function moderateUser() {
        // Call function only for guests
        // Call only for $_POST with variables
        if (JFactory::getUser()->id || $_SERVER['REQUEST_METHOD'] != 'POST') {
            return false;
        }
        $post = $_POST;
        $ver = new JVersion();
        if (strcmp($ver->RELEASE, '1.5') <= 0) {
            $post_name = isset($post['name']) ? $post['name'] : null;
            $post_username = isset($post['username']) ? $post['username'] : null;
            $post_email = isset($post['email']) ? $post['email'] : null;
        } else {
            $post_name = isset($post['name']) ? $post['name'] : (isset($post['jform']['name']) ? $post['jform']['name'] : null);
            $post_username = isset($post['username']) ? $post['username'] : (isset($post['jform']['username']) ? $post['jform']['username'] : null);
            $post_email = isset($post['email']) ? $post['email'] : (isset($post['jform']['email1']) ? $post['jform']['email1'] : null);
        }

        $session = JFactory::getSession();

        $ctResponse = self::ctSendRequest(
                'check_newuser', array(
                    'sender_email' => $post_email,
                    'sender_nickname' => $post_username,
                )
        );
        if ($ctResponse)
        {
	        if (!empty($ctResponse) && is_array($ctResponse)) {
	            if ($ctResponse['allow'] == 0) {
	                if ($ctResponse['errno'] != 0) {
	                    $this->sendAdminEmail("CleanTalk plugin", $ctResponse['comment']);
	                } else {
	                    $session->set('ct_register_form_data', $post);

	                    $app =  JFactory::getApplication();
	                    $app->enqueueMessage($ctResponse['comment'], 'error');

	                    $uri = JUri::getInstance();
	                    $redirect = $uri->toString();

	                    // OPC
	                    if (isset($_POST['return'])) {
	                        $redirect_opc = base64_decode($_POST['return']);
	                        $u =& JURI::getInstance( $redirect);
	                        $u_opc =& JURI::getInstance( $redirect_opc );

	                        if ($u->getHost() == $u_opc->getHost()) {
	                            $app->redirect(base64_decode($_POST['return']));
	                            die;    
	                        }
	                    }

	                    $redirect = str_replace('?task=registration.register', '', $redirect);
	                    $app->redirect($redirect);
	                    die();
	                }
	            } else {
	            	$ct = new Cleantalk();
	                $comment = $ct->addCleantalkComment("", $ctResponse['comment']);
	                $hash = $ct->getCleantalkCommentHash($comment);

	                $session->set('register_username', $post_username);
	                $session->set('register_email', $post_email);
	                $session->set('ct_request_id', $hash);
	            }
	        }        	
        }

    }


    private function sendAdminEmail($subject, $message, $is_html = false) {
        $app = JFactory::getApplication();
        
        $mail = JFactory::getMailer();
        $mail->addRecipient($app->getCfg('mailfrom'));
        $mail->setSender(array($app->getCfg('mailfrom'), $app->getCfg('fromname')));
        $mail->setSubject($subject);
        $mail->setBody($message);
        $mail->isHTML($is_html);
        $sent = $mail->Send();
    }

    private function ctSendRequest($method, $params) 
    {
		// Don't send request if current url is in exclusions list
		$url_exclusion = CleantalkCustomConfig::get_url_exclusions();
		if ($url_exclusion)
		{
			foreach ($url_exclusion as $key=>$value)
				if (strpos($_SERVER['REQUEST_URI'],$value) !== false)
				    return;
		}

        $config = $this->getCTConfig();
        		
        $ct_request = new CleantalkRequest;

        foreach ($params as $k => $v) {
            $ct_request->$k = $v;
        }

        $ct_request->auth_key = $config['apikey'];
        $ct_request->agent = self::ENGINE; 
        $ct_request->submit_time = $this->submit_time_test();        
        $ct_request->sender_ip = CleantalkHelper::ip_get(array('real'), false);
        $ct_request->x_forwarded_for = CleantalkHelper::ip_get(array('x_forwarded_for'), false);
        $ct_request->x_real_ip       = CleantalkHelper::ip_get(array('x_real_ip'), false);
        $ct_request->sender_info = $this->get_sender_info();
        $ct_request->js_on = $this->get_ct_checkjs($_COOKIE);

        $result = NULL;
        $ct = new Cleantalk();
        $ct->server_url = $config['server_url'];
        $ct->work_url = $config['work_url'];
        $ct->server_ttl = $config['server_ttl'];
        $ct->server_changed = $config['server_changed'];
        
        switch ($method) {
            case 'check_message':
                $result = $ct->isAllowMessage($ct_request);
                break;
            case 'send_feedback':
                $result = $ct->sendFeedback($ct_request);
                break;
            case 'check_newuser':
                $result = $ct->isAllowUser($ct_request);
                break;
            default:
                return NULL;
        }

        if ($ct->server_change) {
            self::dbSetServer($ct->work_url, $ct->server_ttl, time());
        }
        // Result should be an 	associative array 
        $result = json_decode(json_encode($result), true);
        
        $connection_reports = $config['connection_reports'];
        if(isset($result['errno']) && intval($result['errno']) !== 0 && intval($ct_request->js_on)==1)
        {
        	$result['allow'] = 1;
        	$result['errno'] = 0;
        	$connection_reports['negative']++;
        	if (isset($result['errstr']))
        		$connection_reports['negative_report'][] = array('date'=>date("Y-m-d H:i:s"),'page_url'=>$_SERVER['REQUEST_URI'],'lib_report'=>$result['errstr']);
        }
        if(isset($result['errno']) && intval($result['errno']) !== 0 && intval($ct_request->js_on)!=1)
        {
        	$result['allow'] = 0;
        	$result['spam'] = 1;
        	$result['stop_queue'] = 1;
        	$result['comment']='Forbidden. Please, enable Javascript.';
        	$result['errno'] = 0;
        	$connection_reports['negative']++;
        }
        if (isset($result['errno']) && intval($result['errno']) === 0 && $result['errstr'] == '')
        	$connection_reports['success']++;
        
		$save_params['connection_reports'] = $connection_reports;
		$this->saveCTConfig($save_params);

        return $result;
    }

    /**
     * Interface to get CT options 
     * @return array 
     */
    private function getCTConfig() 
    {
        $plugin = JPluginHelper::getPlugin($this->_type, $this->_name);
  		$jreg = new JRegistry($plugin->params);

		$config['show_notice'] = intval($jreg->get('show_notice', 0));
		$config['renew'] = intval($jreg->get('renew', 0));
		$config['trial'] = intval($jreg->get('trial', 0));
		$config['user_token'] = $jreg->get('user_token', '');
		$config['spam_count'] = intval($jreg->get('spam_count',0));	
		$config['moderate_ip'] = intval($jreg->get('moderate_ip',0));
		$config['moderate'] = intval($jreg->get('moderate',0));
		$config['show_review'] = intval($jreg->get('show_review',0));
		$config['service_id'] = $jreg->get('service_id','');
		$config['license_trial'] = intval($jreg->get('license_trial',0));
		$config['valid'] = intval($jreg->get('valid', 0));
		$config['auto_update_app'] = intval($jreg->get('auto_update_app', 0));
		$config['show_auto_update_notice'] = intval($jreg->get('show_auto_update_notice', 0));	
		$config['ip_license'] = intval($jreg->get('ip_license',0));	

		$config['apikey'] = trim($jreg->get('apikey', ''));
		$config['ct_key_is_ok'] = intval($jreg->get('ct_key_is_ok',0));
		$config['sfw_enable'] = intval($jreg->get('sfw_enable', 0));
		$config['check_external'] = intval($jreg->get('check_external', 0));
		$config['check_all_post'] = intval($jreg->get('check_all_post', 0));
		$config['sfw_last_check'] = intval($jreg->get('sfw_last_check', 0));
		$config['sfw_check_interval'] = intval($jreg->get('sfw_check_interval', 86400));
		$config['sfw_last_send_log'] = intval($jreg->get('sfw_last_send_log', 0));
		$config['sfw_reload_timeout'] = intval($jreg->get('sfw_reload_timeout', 3));
		$config['server_url'] = $jreg->get('server_url', 'http://moderate.cleantalk.org');
		$config['work_url'] = $jreg->get('work_url', '');
		$config['server_ttl'] = intval($jreg->get('server_ttl', 0));
		$config['server_changed'] = intval($jreg->get('server_changed', 0));
		$config['acc_status_last_check'] = intval($jreg->get('acc_status_last_check', 0));
		$config['acc_status_check_interval'] = intval($jreg->get('acc_status_check_interval', 86400));
		$config['jcomments_unpublished_nofications'] = intval($jreg->get('jcomments_unpublished_nofications', 0));
		$config['general_contact_forms_test'] = intval($jreg->get('general_contact_forms_test', 0));
		$config['relevance_test'] = intval($jreg->get('relevance_test', 0));
		$config['tell_about_cleantalk'] = intval($jreg->get('tell_about_cleantalk', 0));
		$config['js_keys'] = json_decode(json_encode($jreg->get('js_keys',array())),true);
		$config['js_keys_store_days'] = intval($jreg->get('js_keys_store_days',14));
		$config['js_key_lifetime'] = intval($jreg->get('js_key_lifetime',86400));
		$config['show_review_done'] = intval($jreg->get('show_review_done',0));						
		$config['connection_reports']= json_decode(json_encode($jreg->get('connection_reports',array('success' => 0, 'negative'=> 0,'negative_report' => null))),true);
		
        return $config;
    }

    /**
     * Current server setter
     * $ct_work_url
     * $ct_server_ttl
     * $ct_server_changed
     * @return null
     */
    private function dbSetServer($ct_work_url, $ct_server_ttl, $ct_server_changed) 
    {
		$save_params['work_url'] = $ct_work_url;
		$save_params['server_ttl'] = $ct_server_ttl;
		$save_params['server_changed'] = $ct_server_changed;

		$this->saveCTConfig($save_params);
    }
  
    /**
    * Get value of $ct_checkjs
    * JavaScript avaibility test.
    * @return null|0|1
    */
    private function get_ct_checkjs($data){

    	$config = $this->getCTConfig();
        if (!$data)
        	return;

		$checkjs = null;
		$js_post_value = null;

        if (isset($data['ct_checkjs'])) {
        	$js_post_value = $data['ct_checkjs'];
            $keys = $config['js_keys'];
            $checkjs = isset($keys[$js_post_value]) ? 1 : 0;
        }

        $option_cmd = JFactory::getApplication()->input->get('option');
        // Return null if ct_checkjs is not set, because VirtueMart not need strict JS test
        if (!isset($data['ct_checkjs']) && $option_cmd = 'com_virtuemart')
           $checkjs = null; 
        
        return $checkjs;
    }

    /**
     * Gets HTML code with link to Cleantalk site
     * @return null 
     * @since 1.5
     */
    private function getJSTest() 
    {
        $value = $this->cleantalk_get_checkjs_code();
        /*
            JavaScript validation via Cookies
        */
        $field_name = 'ct_checkjs';
        $get_funcs = file_get_contents(dirname(__FILE__) . DS. "js". DS. "ct-functions.js");
        $html = str_replace("{value}", $value, $get_funcs);
		$html = sprintf($html, $field_name, $value);

        return $html;
        
    } 
    /**
     * Valids email 
     * @return bool 
     * @since 1.5
     */
    private function validEmail($string) 
    {
        if (!isset($string) || !is_string($string)) {
            return false;
        }
        
        return preg_match("/^\S+@\S+$/i", $string); 
    }
    
    /**
     * Validate form submit time 
     *
     */
    private function submit_time_test() 
    {
    	return $this->ct_cookies_test() ? time() - intval($_COOKIE['ct_timestamp']) : null;
    }
    
    /**
     * Inner function - Default data array for senders 
     * @return array 
     */
    private function get_sender_info() 
    {
        $page_set_timestamp = (isset($_COOKIE['ct_ps_timestamp']) ? $_COOKIE['ct_ps_timestamp'] : 0);
        $js_timezone = (isset($_COOKIE['ct_timezone']) ? $_COOKIE['ct_timezone'] : '');
        $first_key_timestamp = (isset($_COOKIE['ct_fkp_timestamp']) ? $_COOKIE['ct_fkp_timestamp'] : '');
        $pointer_data = (isset($_COOKIE['ct_pointer_data']) ? json_decode($_COOKIE['ct_pointer_data']) : '');   

        $config = $this->getCTConfig();
        
        $sender_info = array(
            'REFFERRER' => (isset($_SERVER['HTTP_REFERER']))?htmlspecialchars((string) $_SERVER['HTTP_REFERER']):null,
            'post_url' => (isset($_SERVER['HTTP_REFERER']))?htmlspecialchars((string) $_SERVER['HTTP_REFERER']):null,
            'USER_AGENT' => (isset($_SERVER['HTTP_USER_AGENT']))?htmlspecialchars((string) $_SERVER['HTTP_USER_AGENT']):null,
            'js_timezone' => $js_timezone,
            'mouse_cursor_positions' => $pointer_data,
            'key_press_timestamp' => $first_key_timestamp,
            'page_set_timestamp' => $page_set_timestamp,            
            'direct_post' => $this->ct_direct_post,
            'cookies_enabled' => $this->ct_cookies_test(), 
            'ct_options'=>json_encode($config),
            'REFFERRER_PREVIOUS' => isset($_COOKIE['ct_prev_referer'])?$_COOKIE['ct_prev_referer']:null,
            'fields_number'   => sizeof($_POST),
        );
        return json_encode($sender_info);
    }

	/*
	 * Set Cookies test for cookie test
	 * Sets cookies with pararms timestamp && landing_timestamp && pervious_referer
	 * Sets test cookie with all other cookies
	 */
	private function ct_cookie(){
		
		$config = $this->getCTConfig();
		
		// Cookie names to validate
		$cookie_test_value = array(
			'cookies_names' => array(),
			'check_value' => $config['apikey'],
		);

		// Submit time
		$ct_timestamp = time();
		setcookie('ct_timestamp', $ct_timestamp, 0, '/');
		$cookie_test_value['cookies_names'][] = 'ct_timestamp';
		$cookie_test_value['check_value'] .= $ct_timestamp;

        // Pervious referer
        if(!empty($_SERVER['HTTP_REFERER'])){
            setcookie('ct_prev_referer', $_SERVER['HTTP_REFERER'], 0, '/');
            $cookie_test_value['cookies_names'][] = 'ct_prev_referer';
            $cookie_test_value['check_value'] .= $_SERVER['HTTP_REFERER'];
        }			

		// Cookies test
		$cookie_test_value['check_value'] = md5($cookie_test_value['check_value']);
		setcookie('ct_cookies_test', json_encode($cookie_test_value), 0, '/');
	}

	/**
	 * Cookies test for sender 
	 * @return null|0|1;
	 */
	private function ct_cookies_test()
	{
		$config = $this->getCTConfig();
		
		if(isset($_COOKIE['ct_cookies_test'])){
			
			$cookie_test = json_decode(stripslashes($_COOKIE['ct_cookies_test']), true);
			
			$check_srting = $config['apikey'];
			foreach($cookie_test['cookies_names'] as $cookie_name){
				$check_srting .= isset($_COOKIE[$cookie_name]) ? $_COOKIE[$cookie_name] : '';
			} unset($cokie_name);
			
			if($cookie_test['check_value'] == md5($check_srting)){
				return 1;
			}else{
				return 0;
			}
		}else{
			return null;
		}
	}    
	private function get_spam_comments($offset=0,$on_page=20,$improved_check =false)
	{
		$db = JFactory::getDBO();$config = $this->getCTConfig();
        $output['result']=null;
        $output['data']=null;
        $data = array();$spam_comments=array();        
		$db->setQuery("SHOW TABLES LIKE '%jcomments'");
		$improved_check = ($_POST['improved_check'] == 'true')?true:false;
		$amount = $on_page;
	    $last_id = $offset;		
		$jtable = $db->loadAssocList();
		if (empty($jtable))
		{
        	$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_JCOMMENTSNOTINSTALLED');
        	$output['result'] = 'error';  				
		}
        else 
        {
        	while (count($spam_comments)<$on_page)
        	{
        		if ($last_id>0)
        		{
        			$offset=0;
        			$db->setQuery("SELECT * FROM `#__jcomments` WHERE id > ".$last_id." LIMIT ".$offset.", ".$amount);
        		}
	            $db->setQuery("SELECT * FROM `#__jcomments` LIMIT ".$offset.", ".$amount);
	            $comments = $db->loadAssocList();   
	            if (empty($comments))
	            	break;         	
	            foreach ($comments as $comment)
	            {
	            	$curr_date = (substr($comment['date'], 0, 10) ? substr($comment['date'], 0, 10) : '');
	            	if (!empty($comment['ip']))
	            		$data[$curr_date][]=$comment['ip'];
	            	if (!empty($comment['email']))
	            		$data[$curr_date][]=$comment['email'];
	            }
	            if (count($data) == 0)
	            {
	            	$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOCOMMENTSTOCHECK');
	            	$output['result'] = 'error';  	            	  
	            }
	            else
	            {
	            	foreach ($data as $date => $values)
	            	{
	            		$values=implode(',',$values);
	            		$request=Array();
			        	$request['method_name'] = 'spam_check_cms';
			        	$request['auth_key'] = $config['apikey'];
			        	$request['data'] = $values;
			        	if ($improved_check)
			        		$request['date'] = $date;
			        	$url='https://api.cleantalk.org';
			        	$result=CleantalkHelper::api_send_request($request);
			       		$result=json_decode($result);
			       		if (isset($result->error_message))
			       		{
			       			if ($result->error_message == 'Access key unset.' || $result->error_message == 'Unknown access key.')
			       				$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY');
		       				elseif ($result->error_message == 'Service disabled, please go to Dashboard https://cleantalk.org/my?product_id=1')
		       					$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY_DISABLED');
		       				elseif ($result->error_message == 'Calls limit exceeded, method name spam_check_cms().')
		       					$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_CALLS_LIMIT_EXCEEDED');		       			
			       			else $output['data'] = $result->error_message;	       			
			       			$output['result']='error';
			       		}
			       		else
			       		{
			       			if (isset($result->data))
			       			{
			       				foreach($result->data as $mail=>$value)
			       				{
			       					if ($value->appears == '1' )
			       					{
			       						foreach ($comments as $comment)
			       						{
			       							if (($comment['email']==$mail || $comment['ip']==$mail) && substr($comment['date'], 0, 10) == $date && count($spam_comments)<$on_page)
			       								$spam_comments[]=$comment;

			       						}
			       					}
			       				}
			       			}    			
			       		}
	            	}	            	
	            }
			    $offset+=$amount;
			    $amount = $on_page-count($spam_comments);
			    if (count($comments)<$on_page)
			    	break;	                     		
        	}
        	if ($output['result'] != 'error')
        	{
		       	if (count($spam_comments)>0)
		       	{
		        	$output['data']['spam_comments']=$spam_comments;
			        $output['result']='success';       				
		       	}
		       	else 
		       	{
		       		$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOCOMMENTSFOUND');
		 			$output['result']='error';        					
		       	} 	            		
        	}        	
          	
        }
        return $output;      				
	}
	private function get_spam_users($offset=0, $on_page = 20, $improved_check = false)
	{
		$db = JFactory::getDBO();$config = $this->getCTConfig();
	    $data = array();$spam_users=array();
	    $output['result']=null;
	    $output['data']=null;
	    $amount = $on_page;
	    $last_id = $offset;
	    while(count($spam_users)<$on_page)
	    {
	    	if ($last_id>0){
	    		$offset=0;
	    		$db->setQuery("SELECT * FROM `#__users` WHERE id > ".$last_id." LIMIT ".$offset.", ".$amount);
	    	}
	    	else $db->setQuery("SELECT * FROM `#__users` LIMIT ".$offset.", ".$amount);		    
		    $users = $db->loadAssocList();
		    if (empty($users))
		    	break;
		    foreach ($users as $user_index => $user)
		    {
		    	$curr_date = (substr($user['registerDate'], 0, 10) ? substr($user['registerDate'], 0, 10) : '');
		    	if (!empty($user['email']))
			          	$data[$curr_date][] = $user['email'];
		    }
		    if (count($data) == 0)
		    {
		    	$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOUSERSTOCHECK');
		    	$output['result'] = 'error';
		    }
		    else 
		    {
		    	foreach ($data as $date=>$values)
		    	{		    		
		    		$values=implode(',',$values);
					$request=Array();
		        	$request['method_name'] = 'spam_check_cms';
		        	$request['auth_key'] = $config['apikey'];
		        	$request['data'] = $values;
		        	if ($improved_check)
		        		$request['date'] = $date;
		        	$url='https://api.cleantalk.org';
		        	$result=CleantalkHelper::api_send_request($request);
		       		$result=json_decode($result);  
		       		if (isset($result->error_message))
		       		{
		       			if ($result->error_message == 'Access key unset.' || $result->error_message == 'Unknown access key.')
		       				$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY');
		       			elseif ($result->error_message == 'Service disabled, please go to Dashboard https://cleantalk.org/my?product_id=1')
		       				$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY_DISABLED');
		       			elseif ($result->error_message == 'Calls limit exceeded, method name spam_check_cms().')
		       				$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_CALLS_LIMIT_EXCEEDED');
		       			else $output['data'] = $result->error_message;	       			
		       			$output['result']='error';
		       		}
		       		else
		       		{
		       			if (isset($result->data))
		       			{
		       				foreach($result->data as $mail=>$value)
		       				{
		       					if ($value->appears == '1' )
		       					{
		       						foreach ($users as $user)
		       						{
		       							if ($user['email']==$mail && substr($user['registerDate'], 0, 10) == $date)
		       							{
			       							if ($user['lastvisitDate'] == '0000-00-00 00:00:00')
			       								$user['lastvisitDate'] = '-';
		       								if (count($spam_users)<$on_page)			       								
			       								$spam_users[]=$user;

		       							}
		       						}
		       					}
		       				}
		       			}
		       		}	           		
		    	}

		    }
		    $offset+=$amount;
		    $amount = $on_page-count($spam_users);
		    if (count($users)<$on_page)
		    	break;
	    }
    	if ($output['result'] != 'error')
    	{
	       	if (count($spam_users)>0)
	       	{
	        	$output['data']['spam_users']=$spam_users;
		        $output['result']='success';       				
	       	}
	       	else 
	       	{
            	$output['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOUSERSFOUND');
	       		$output['result']='error';
	       	}              		
    	}	   	
	    return $output;      
	}
	private function dev_insert_spam_users()
	{
        $db = JFactory::getDBO();
        $prefix = $db->getPrefix();
        $query = "INSERT INTO `#__users` (name,username,email,registerDate,lastvisitDate,params,lastResetTime) VALUES ";
        for ($i=1;$i<=30;$i++)
        {
        	$row="(";
	        $row.="'spam_user$i',";
	        $row.="'spam_user$i',";
	        $row.="'s@cleantalk.org',";
	        $row.="'2018-01-17 02:54:19',";
	        $row.="'2018-01-17 02:54:19',";
	        $row.="'{\"admin_style\":\"\",\"admin_language\":\"\",\"language\":\"\",\"editor\":\"\",\"helpsite\":\"\",\"timezone\":\"\"}',";
	        $row.="'2018-01-17 02:54:19'";
	        $row.=")";
	        if ($i !== 30)
	        	$row.=",";
	        $query.=$row;    	
        }
        $db->setQuery($query);
        if ($db->execute())
			$output['result']='success!';
		else $output['result']='error!';
		return $output;
	}
    private function sfw_check()
    {
    	$config = $this->getCTConfig();
    	$app = JFactory::getApplication(); 

		if (!$app->isAdmin() && $config['sfw_enable'] == 1 && $_SERVER["REQUEST_METHOD"] == 'GET')
		{
		   	$is_sfw_check = true;
			$sfw = new CleantalkSFW();
			$sfw->ip_array = (array)CleantalkSFW::ip_get(array('real'), true);	
				
            foreach($sfw->ip_array as $key => $value)
            {
		        if(isset($_COOKIE['ct_sfw_pass_key']) && $_COOKIE['ct_sfw_pass_key'] == md5($value . $config['apikey']))
		        {
		          $is_sfw_check=false;

		          if(isset($_COOKIE['ct_sfw_passed']))
		          {
		            @setcookie ('ct_sfw_passed'); //Deleting cookie
		            $sfw->sfw_update_logs($value, 'passed');
		          }
		        }
	      	} unset($key, $value);	

			if($is_sfw_check)
			{
				$sfw->check_ip();
				if($sfw->result)
				{
					$sfw->sfw_update_logs($sfw->blocked_ip, 'blocked');
					$sfw->sfw_die($config['apikey']);
				}
			}

	        $save_params = array();    

            if ($config['sfw_check_interval'] > 0 && ($config['sfw_last_check'] + $config['sfw_check_interval']) < time() && $config['apikey'] !== '') 
            {
                $sfw->sfw_update($config['apikey']);
                $save_params['sfw_last_check'] = time();
            }

            if(time()-$config['sfw_last_send_log'] > 3600)
            {
            	$sfw->send_logs($config['apikey']);
            	$save_params['sfw_last_send_log'] = time();
            }

            $this->saveCTConfig($save_params);
		}    	
    }
    private function saveCTConfig($params)
    {
    	if (count($params) > 0)
    	{
    		$table = JTable::getInstance('extension');
    		$table->load($this->_id);
    		$jparams = new JRegistry($table->params);
    		foreach($params as $k => $v)
    			$jparams->set($k, $v);
    		$table->params = $jparams->toString();
    		$table->store();
    	}
    }		   
}

