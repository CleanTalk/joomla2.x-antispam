<?php

/**
 * CleanTalk joomla plugin
 *
 * @version 4.9.3
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
if(!defined('DS')){
    define('DS', DIRECTORY_SEPARATOR);
}
require_once(dirname(__FILE__) . DS . 'cleantalk.class.php');

class plgSystemAntispambycleantalk extends JPlugin {
    /**
     * Plugin version string for server
     */
    const ENGINE = 'joomla3-493';
    
    /**
     * Default value for hidden field ct_checkjs 
     */
    const CT_CHECKJS_DEF = 0;

    /**
     * Cleantalk instance
     */
    static $CT;

    /**
     * Tables exist and ready flag
     * If set then tables exist and filled by initial data
     */
    static $tables_ready = FALSE;

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = false;

    /*
     * Flag marked JComments form initilization. 
     */
    private $JCReady = false;

    /*
     * Page load label
     */
    private $form_load_label = 'formtime';
    
    /*
     * Page load label
     */
    private $current_page = null;
    
    /**
     * Form submited without page load
     */
    private $ct_direct_post = 0;

    /**
     * Admin notice counter to prevent to show notice twice.
     */
    private $ct_admin_notices = 0;

    /**
     * Components list to skip onSpamCheck()
     */
    private $skip_coms = array(
        'com_jcomments',
        'com_contact',
        'com_virtuemart',
        'com_users',
        'com_user',
        'com_login',
        'com_akeebasubs'
    );
    
    private $is_executed=false;

    /**
     * Parametrs list to skip onSpamCheck()
     */
     private $skip_params = array(
	'ipn_track_id', // PayPal IPN #
	'txn_type', // PayPal transaction type
     );
     
     /**
     * Flag for saving new apikey
     */
     
     private $ct_is_newkey=false;

     /**
     * SpamFireWall table name
     */
    private $sfw_table_name = '#__sfw_networks';
     
     /**
     * SpamFireWall cookie name
     */
    private $sfw_cookie_lable = 'ct_sfw_pass_key';

    /**
     * Constructor
     * @access public
     * @param $subject
     * @param $config
     * @return void
     */
    public function __construct (&$subject, $config) {
        parent::__construct($subject, $config);
    }
  
    private function cleantalk_get_checkjs_code()
    {
    	$config = $this->getCTConfig();
    	$api_key = isset($config['apikey']) ? $config['apikey'] : null;
    	$js_keys = isset($config['js_keys']) ? json_decode($config['js_keys'], true) : null;
    	if($js_keys == null){
		
		$js_key = strval(md5($api_key . time()));
		
		$js_keys = array(
			'keys' => array(
				array(
					time() => $js_key
				)
			), // Keys to do JavaScript antispam test 
			'js_keys_amount' => 24, // JavaScript keys store days - 8 days now
			'js_key_lifetime' => 86400, // JavaScript key life time in seconds - 1 day now
		);
		
		}else{
			
			$keys_times = array();
			
			foreach($js_keys['keys'] as $time => $key){
				
				if($time + $js_keys['js_key_lifetime'] < time())
					unset($js_keys['keys'][$time]);
				
				$keys_times[] = $time;

			}unset($time, $key);
			
			if(max($keys_times) + 3600 < time()){
				$js_key =  strval(md5($api_key . time()));
				$js_keys['keys'][time()] = $js_key;
			}else{
				$js_key = $js_keys['keys'][max($keys_times)];
			}
		
	}
	$id=0;
    $id=$this->getId('system','antispambycleantalk');
    $table = JTable::getInstance('extension');
    $table->load($id);
		$params   = new JRegistry($table->params);
		$params->set('js_keys', json_encode($js_keys));
		$table->params = $params->toString();
		$table->store();
	return $js_key;	

    }  
    public function check_url_exclusions(){
		
		global $cleantalk_url_exclusions;
		
		if(isset($cleantalk_url_exclusions) && count($cleantalk_url_exclusions) > 0){
			
			$result = false;
			foreach($cleantalk_url_exclusions as $value){
				
				if(stripos($_SERVER['REQUEST_URI'], $value) !== false)
					$result=true;

			} unset($value);
			
		}else
			$result=false;
		
		return $result;
	}
    
    /*
    * Get id of CleanTalk extension
    */
    
	function getId($folder,$name)
	{
		$db=JFactory::getDBO();
		if(!version_compare(JVERSION, '3', 'ge')){ //joomla 2.5
		
			$sql='SELECT extension_id FROM #__extensions WHERE folder ="'.$db->getEscaped($folder).'" AND element ="'.$db->getEscaped($name).'"';
			$db->setQuery($sql);
			
		}else{
			
			$query = $db->getQuery(true);
			$query
				->select($db->quoteName('a.extension_id'))
				->from($db->quoteName('#__extensions', 'a'))
				->where($db->quoteName('a.element').' = '.$db->quote($name))
				->where($db->quoteName('a.folder').' = '.$db->quote($folder));
			$db->setQuery($query);
			$db->execute();
		}
		if(!($plg=$db->loadObject()))
			return 0;
		else
			return (int)$plg->extension_id;
		
	}
	
	/*
	* Checks if auth_key is paid or not
	*/
    
	private function checkIsPaid($ct_api_key=null, $force_check = false){
		
    	$id=0;
    	$id=$this->getId('system','antispambycleantalk');

    	if($id!==0){			
    		$component = JRequest::getCmd( 'component' );
			$table = JTable::getInstance('extension');
    		$table->load($id);
    		if($table->element=='antispambycleantalk'){				
    			$plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
				$jparam = new JRegistry($plugin->params);
				$last_checked=$jparam->get('last_checked', 0);
				$new_checked=time();
				$last_status=intval($jparam->get('last_status', -1));
				$sfw_enable = $jparam->get('sfw_enable',0);
				$api_key = trim($ct_api_key);	
				$new_status=$last_status;	
				if($new_checked-$last_checked > 86400  || $force_check)
				{
					if (empty($api_key))
						$key_is_ok=false;
					else
					{
						$url='https://api.cleantalk.org';
						$dt = array(
							"method_name" => "notice_validate_key",
							"auth_key" => $api_key,
							"path_to_cms" => $_SERVER['HTTP_HOST']
						);						
						$result= sendRawRequest($url, $dt);
						$result = $result ? json_decode($result, true) : false;						
						$key_is_ok = isset($result) ? $result['valid'] : 0;	

					}
					$params   = new JRegistry($table->params);
					if ($key_is_ok)
					{
						// get_account_status
						$url = 'https://api.cleantalk.org';
				    	$dt=Array(
				    		'auth_key'=>$api_key,
				    		'method_name'=> 'get_account_status');
				    	$result = sendRawRequest($url,$dt);							
				    	if($result!==null)
				    	{
				    		$result=json_decode($result);
				    		if(isset($result->data)&&isset($result->data->paid))
				    		{
				    			$new_status=intval($result->data->paid);
				    			//set notice
				    			if($last_status!=1&&$new_status==1)
				    					$show_notice=1;
				    		}
				    	}							
				    	$result = noticePaidTill($api_key);
				    	if($result !== null)
				    	{
				    		$result = json_decode($result);
				    		if(isset($result->data) && !empty($result->data->show_review) && $result->data->show_review == 1)
			    				$show_notice_review = 1;
			    			else $show_notice_review=0;
							$user_token = (isset($result->data->user_token))?$result->data->user_token:'';
							$service_id = (isset($result->data->show_notice) && $result->data->show_notice == 1 && isset($result->data->trial) && $result->data->trial == 1)?'':$result->data->service_id;
							$spam_count = (isset($result->data->spam_count))?$result->data->spam_count:0;
							$moderate_ip = (isset($result->data->moderate_ip) && $result->data->moderate_ip == 1)?1:0;
							if ($sfw_enable ==1)
								self::update_sfw_db_networks($api_key);
							self::ctSendAgentVersion($api_key);
				    	}
						$params->set('last_checked', $new_checked);
						$params->set('last_status', $new_status);
						$params->set('show_notice', (isset($show_notice)?$show_notice:0));
				    	$params->set('show_notice_review', $show_notice_review); // Temporary
				    	$params->set('user_token',$user_token);
				    	$params->set('service_id',$service_id);
				    	$params->set('spam_count',$spam_count);	
				    	$params->set('moderate_ip',$moderate_ip);
				    	$params->set('ct_key_is_ok', 1);	
				    	$params->set('show_notice_review_done',$jparam->get('show_notice_review_done', 0));	    									
					}
					else 
					{
						if (isset($result['error_no']) && $result['error_message']=='Calls limit exceeded, method name notice_validate_key().')
							JError::raiseNotice(1024, JText::_('PLG_SYSTEM_CLEANTALK_CALLS_LIMIT_EXCEEDED'));
						$params->set('ct_key_is_ok', 0);
						$params->set('user_token', '');
						$params->set('service_id','');
						$params->set('spam_count',0);
						$params->set('last_checked', time());
					}
				}	
						
    		}
    	}
    	return (isset($params)?$params:null);
    }
    
    /*
	* Get data from submit recursively
	*/
	
	function getFieldsAny($arr, $message=array(), $email = null, $nickname = array('nick' => '', 'first' => '', 'last' => ''), $subject = null, $contact = true, $prev_name = ''){
		
		$obfuscate_params = array( //Fields to replace with ****
			'password',
			'pass',
			'pwd',
			'pswd'
		);
		
		$skip_fields_with_strings = array( //Array for strings in keys to skip and known service fields
			// Payment
			'ipn_track_id', 	// PayPal IPN #
			'txn_type', 		// PayPal transaction type
			'payment_status', 	// PayPal payment status
			'ccbill_ipn', 		//CCBill IPN 
			//Common
			'ct_checkjs', //Do not send ct_checkjs
			'nonce', //nonce for strings such as 'rsvp_nonce_name'
			'security',
			'action',
			'http_referer'
		);
		
		if(count($arr)){
			foreach($arr as $key => $value){
				
				if(gettype($value)=='string'){
					$decoded_json_value = json_decode($value, true);
					if($decoded_json_value !== null)
						$value = $decoded_json_value;
				}
				
				if(!is_array($value) && !is_object($value)){
					
					if($value === '')
						continue;
					
					//Skipping fields names with strings from (array)skip_fields_with_strings
					foreach($skip_fields_with_strings as $needle){
						if (strpos($prev_name.$key, $needle) !== false){
							continue(2);
						}
					}unset($needle);
					
					//Obfuscating params
					foreach($obfuscate_params as $needle){
						if (strpos($key, $needle) !== false){
							$value = $this->obfuscate_param($value);
							continue(2);
						}
					}unset($needle);
					
					//Email
					if (!$email && preg_match("/^\S+@\S+\.\S+$/", $value)){
						$email = $value;
						
					//Names
					}elseif (preg_match("/name/i", $key)){
											
						if(preg_match("/first/i", $key) || preg_match("/fore/i", $key) || preg_match("/private/i", $key))
							$nickname['first'] = $value;
						elseif(preg_match("/last/i", $key) || preg_match("/sur/i", $key) || preg_match("/family/i", $key) || preg_match("/second/i", $key))
							$nickname['last'] = $value;
						elseif(!$nickname['nick'])
							$nickname['nick'] = $value;
						else
							$message[$prev_name.$key] = $value;
					
					//Subject
					}elseif ($subject === null && preg_match("/subj/i", $key)){
						$subject = $value;
					
					//Message
					}else{
						$message[$prev_name.$key] = $value;					
					}
					
				}else if(!is_object($value)&&@get_class($value)!='WP_User'){
					
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
	public function obfuscate_param($value = null) {
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
    
    public function onAfterInitialise(){
		
    	$session = JFactory::getSession();
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
			//do nothing
        }else{
           if(!(isset($_GET['option']) && $_GET['option'] == 'com_extrawatch') && !(isset($_GET['checkCaptcha']) && $_GET['checkCaptcha'] == 'true') && strpos($_SERVER['REQUEST_URI'],'securimage_show.php')===false){
			   
            	$session->set($this->form_load_label, time());
            	$session->set('cleantalk_current_page', JURI::current());
				
            }
        }

        $app = JFactory::getApplication(); 
        $plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
        $jparam = new JRegistry($plugin->params);
        $sfw_enable = $jparam->get('sfw_enable', 0);
        $ct_apikey = trim($jparam->get('apikey', 0));
        $sfw_log = (array)$jparam->get('sfw_log', 0);
        /*
            Do SpamFireWall actions for visitors if we have a GET request and option enabled. 
        */
        if($sfw_enable == 1 && !JFactory::getUser()->id && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $sfw_test_ip = null;
            if (isset($_GET['sfw_test_ip']) && preg_match("/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/", $_GET['sfw_test_ip'])) {
                $sfw_test_ip = $_GET['sfw_test_ip'];
            }
            if ($this->swf_do_check($ct_apikey, $sfw_test_ip)) {
                $this->swf_init($ct_apikey, $sfw_test_ip); 
            }else{
            	if(isset($_COOKIE['ct_sfw_passed'])){
					
	    			self::getCleantalk();
	    			$sender_ip = self::$CT->ct_session_ip($_SERVER['REMOTE_ADDR']);
			        if ($sfw_test_ip) {
			            $sender_ip = $sfw_test_ip;
			        }
	    			$sfw_log[$sender_ip]->allow++;
	    			$jparam->set('sfw_log',$sfw_log);
		            $table = JTable::getInstance('extension');$id = $this->getId('system','antispambycleantalk');
		            $table->load($id);
		            $table->params = $jparam->toString();
		            $table->store();	    			
	    			@setcookie ('ct_sfw_passed', '0', 1, "/");
	    		}
            }
        }
        /*
            Sync to local table most spam IP networks
        */
        if($sfw_enable == 1) 
        {
	        $sfw_last_check = $jparam->get('sfw_last_check', 0);     
	        $sfw_last_send_log = $jparam->get('sfw_last_send_log', 0);
	        $save_params = array();        	
            $sfw_check_interval = $jparam->get('sfw_check_interval', 0);
            if ($sfw_check_interval > 0 && ($sfw_last_check + $sfw_check_interval) < time()) 
                self::update_sfw_db_networks($ct_apikey);
            if(time()-$sfw_last_send_log>3600)
            {
            	if(is_array($sfw_log)&&sizeof($sfw_log)>0)
            	{					
            		$data=Array();
			    	foreach($sfw_log as $key=>$value)
			    	{					
			    		if(is_object($value))
			    		{							
			    			if(isset($value->datetime))
			    				$datetime=$value->datetime;
			    			else
			    				$datetime=time();
			    			$data[]=Array($key, $value->all, $value->allow, $datetime);
			    		}
			    	}

			    	$qdata = array (
						'data' => json_encode($data),
						'rows' => count($data),
						'timestamp' => time()
					);
					$result = sendRawRequest('https://api.cleantalk.org/?method_name=sfw_logs&auth_key='.$ct_apikey,$qdata);
					$result = json_decode($result);
					if(isset($result->data) && isset($result->data->rows) && $result->data->rows == count($data))
					{
						$save_params['sfw_log']=Array();
						$save_params['sfw_last_send_log']=time();
					}
            	}
            }
	        //
	        // Save new settings
	        //
	        if (count($save_params)) {
	            $id = $this->getId('system','antispambycleantalk');
	            $table = JTable::getInstance('extension');
	            $table->load($id);
	            
	            $params = new JRegistry($table->params);
	            foreach ($save_params as $k => $v) {
	                $params->set($k, $v);
	            }
	            $table->params = $params->toString();
	            $table->store();
	        }
        }       
		if($app->isAdmin() && strpos(JFactory::getUri(), 'com_plugins&view=plugin&layout=edit&extension_id='.$this->getId('system','antispambycleantalk')))
		{
		//SFW Section
		$this->loadLanguage();		
    	if( isset($_GET['option'])&&$_GET['option']=='com_rsform'&&isset($_POST)&&sizeof($_POST)>0&&!$app->isAdmin() ||
			isset($_POST['option'])&&$_POST['option']=='com_virtuemart'&&isset($_POST['task'])&&$_POST['task']=='saveUser' ||
			isset($_GET['api_controller']) ||
			isset($_GET['task'])&&$_GET['task']=='mailAskquestion'||
			isset($_POST['task'])&&$_POST['task']=='mailAskquestion' ||
			isset($_GET['ajax']) && isset($_GET['username']) && isset($_GET['email']) ||
			isset($_POST['option'])&&$_POST['option']=='com_alfcontact' ||
			isset($_POST['option'])&&$_POST['option']=='com_contact'&&isset($_POST['task'])&&$_POST['task']=='contact.submit'
    	){
    		$sender_email = '';
		    $sender_nickname = '';
		    $subject = '';
		    $contact_form = true;
		    $message = '';
		    
		    if(isset($_GET['ajax'])){
				
		    	$sender_email = $_GET['email'];
		    	$sender_nickname = $_GET['username'];
				
		    }else if(isset($_POST['task'])&&$_POST['task']=='saveUser'){
				
		    	$sender_email = $_POST['email'];
		    	$sender_nickname = $_POST['username'];
				
		    }else{
				
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
		    
    		$result = $this->onSpamCheck(
                '',
                array(
                    'sender_email' => $sender_email, 
                    'sender_nickname' => $sender_nickname, 
                    'message' => $message
                ));
				
            $this->is_executed=true;

            if ($result !== true) {
            	if(isset($_GET['ajax'])){
					
            		print $this->_subject->getError();
            		die();
					
            	}else{
					
            		if(isset($_POST['option'])&&$_POST['option']=='com_alfcontact'){
						
            			$error_tpl=file_get_contents(dirname(__FILE__)."/error.html");
						print str_replace('%ERROR_TEXT%',$this->_subject->getError(),$error_tpl);
						die();
						
            		}else
						JError::raiseError(503, $this->_subject->getError());
					
                }
            }
    	}
    	
    	if(isset($_POST['ct_delete_notice'])&&$_POST['ct_delete_notice']==='yes'){
			
    		/*$id=$this->getId('system','antispambycleantalk');
    		if($id!==0)
    		{
    			$table = JTable::getInstance('extension');
    			$table->load($id);
    			$params   = new JRegistry($table->params);
				$params->set('show_notice',0);
				$table->params = $params->toString();
				$table->store();
    		}
    		$mainframe=JFactory::getApplication();
    		$mainframe->close();*/
    		$ct_db=JFactory::getDBO();
	    	$query="select * from #__extensions where element='antispambycleantalk' and folder='system' ";
	    	$ct_db->setQuery($query,0,1);
	    	$rows=$ct_db->loadObjectList();
	    	if(count($rows)>0){
				
	    		$params=json_decode($rows[0]->params);

	    		$params->show_notice_review_done=1;
	    		$query="update #__extensions set params='".json_encode($params)."' where extension_id=".$rows[0]->extension_id;
	    		//print_r($query);
	    		$ct_db->setQuery($query);
	    		$ct_db->query();
	    		//$rows=@$ct_db->loadObjectList();
	    	}
    		die();
    	}
		
		// Getting key automatically
		if(isset($_POST['get_auto_key']) && $_POST['get_auto_key'] === 'yes'){
						
			$config = JFactory::getConfig();
			$adminmail=$config->get('mailfrom');
				
			$website = $_SERVER['HTTP_HOST'];
			$platform = 'joomla3';
					
				
			$result = getAutoKey($adminmail, $website, $platform);
			$result = $result ? json_decode($result, true) : false;
							
			if (!empty($result['data']) && is_array($result['data'])){
				
				$result = $result['data'];
				// Checks if the user token is empty, then get user token by notice_paid_till()
				if(empty($result['user_token'])){
					
					$result_tmp = noticePaidTill($result['auth_key']);
					$result_tmp = $result_tmp ? json_decode($result_tmp, true) : false;
					
					if (!empty($result_tmp['data']) && is_array($result_tmp['data']))
						$result['user_token'] = $result_tmp['data']['user_token'];
					
				}
			}
				
			print json_encode($result);
			$mainframe=JFactory::getApplication();
			$mainframe->close();
			die();
		}
		//check spam users
		if (isset($_POST['check_users']) && $_POST['check_users'] === 'yes')
		{
			$db = JFactory::getDBO();$config = $this->getCTConfig();
            $db->setQuery("SELECT * FROM `#__users`");
            $users = $db->loadAssocList();
            $data = array();$spam_users=array();
            $send_result['result']=null;
            $send_result['data']=null;
            $improved_check = ($_POST['improved_check'] == 'true')?true:false;
            foreach ($users as $user_index => $user)
            {
            	$curr_date = (substr($user['registerDate'], 0, 10) ? substr($user['registerDate'], 0, 10) : '');
            	if (!empty($user['email']))
  		          	$data[$curr_date][] = $user['email'];
            }
            if (count($data) == 0)
            {
            	$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOUSERSTOCHECK');
            	$send_result['result'] = 'error';
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
		        	$result=sendRawRequest($url, $request);
		       		$result=json_decode($result);   	
		       		if (isset($result->error_message))
		       		{
		       			if ($result->error_message == 'Access key unset.' || $result->error_message == 'Unknown access key.')
		       				$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY');
		       			elseif ($result->error_message == 'Service disabled, please go to Dashboard https://cleantalk.org/my?product_id=1')
		       				$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY_DISABLED');
		       			elseif ($result->error_message == 'Calls limit exceeded, method name spam_check_cms().')
		       				$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_CALLS_LIMIT_EXCEEDED');
		       			else $send_result['data'] = $result->error_message;	       			
		       			$send_result['result']='error';
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
		       								$db->setQuery("UPDATE `#__users` SET ct_marked_as_spam = 1 WHERE id = ".$user['id']);
		       								$db->query();	
		       								if ($user['lastvisitDate'] == '0000-00-00 00:00:00')
		       									$user['lastvisitDate'] = '-';
		       								$spam_users[]=$user;
		       							}
		       						}
		       					}
		       				}
		       			}
		       		}	           		
            	}
            	if ($send_result['result'] != 'error')
            	{
			       	if (count($spam_users)>0)
			       	{
			        	$send_result['data']['spam_users']=$spam_users;
				        $send_result['result']='success';       				
			       	}
			       	else 
			       	{
		            	$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOUSERSFOUND');
			       		$send_result['result']='error';
			       	}              		
            	}
          	             	
            }      
            print json_encode($send_result);
			$mainframe=JFactory::getApplication();
			$mainframe->close();
			die();
		}
		//check spam comments
		if (isset($_POST['check_comments']) && $_POST['check_comments'] === 'yes')
		{
			$db = JFactory::getDBO();$config = $this->getCTConfig();
            $send_result['result']=null;
	        $send_result['data']=null;
			$db->setQuery("SHOW TABLES LIKE '%jcomments'");
			$improved_check = ($_POST['improved_check'] == 'true')?true:false;
			$jtable = $db->loadAssocList();
			if (empty($jtable))
			{
            	$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_JCOMMENTSNOTINSTALLED');
            	$send_result['result'] = 'error';  				
			}
            else 
            {
	            $db->setQuery("SELECT * FROM `#__jcomments`");
	            $comments = $db->loadAssocList();            	
	            $data = array();$spam_comments=array();
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
	            	$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOCOMMENTSTOCHECK');
	            	$send_result['result'] = 'error';  	            	  
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
			        	$result=sendRawRequest($url, $request);
			       		$result=json_decode($result);
			       		if (isset($result->error_message))
			       		{
			       			if ($result->error_message == 'Access key unset.' || $result->error_message == 'Unknown access key.')
			       				$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY');
		       				elseif ($result->error_message == 'Service disabled, please go to Dashboard https://cleantalk.org/my?product_id=1')
		       					$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_BADKEY_DISABLED');
		       				elseif ($result->error_message == 'Calls limit exceeded, method name spam_check_cms().')
		       					$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_CALLS_LIMIT_EXCEEDED');		       			
			       			else $send_result['data'] = $result->error_message;	       			
			       			$send_result['result']='error';
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
			       							if (($comment['email']==$mail || $comment['ip']==$mail) && substr($comment['date'], 0, 10) == $date )
			       								$spam_comments[]=$comment;
			       						}
			       					}
			       				}
			       			}    			
			       		}
	            	}
	            	if ($send_result['result'] != 'error')
	            	{
				       	if (count($spam_comments)>0)
				       	{
				        	$send_result['data']['spam_comments']=$spam_comments;
					        $send_result['result']='success';       				
				       	}
				       	else 
				       	{
				       		$send_result['data'] = JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_NOCOMMENTSFOUND');
				 			$send_result['result']='error';        					
				       	} 	            		
	            	}
	            	
	            }           	
            }      		
            print json_encode($send_result);
			$mainframe=JFactory::getApplication();
			$mainframe->close();
			die();
		}
		if (isset($_POST['ct_del_user_ids']))
		{
			$spam_users = implode(',',$_POST['ct_del_user_ids']);
			$send_result['result']=null;
            $send_result['data']=null;
			try {
				$this->delete_users($spam_users);
				$send_result['result']='success';
				$send_result['data']=JText::sprintf('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_USERS_DELDONE', count($_POST['ct_del_user_ids']));
			}
			catch (Exception $e){
				$send_result['result']='error';
				$send_result['data']=$e->getMessage();
			}
			print json_encode($send_result);
			$mainframe=JFactory::getApplication();
			$mainframe->close();
			die();
		}
		if (isset($_POST['ct_del_comment_ids']))
		{
			$spam_comments = implode(',',$_POST['ct_del_comment_ids']);
			$send_result['result']=null;
            $send_result['data']=null;
			try {
				$this->delete_comments($spam_comments);
				$send_result['result']='success';
				$send_result['data']=JText::sprintf('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_COMMENTS_DELDONE', count($_POST['ct_del_comment_ids']));
			}
			catch (Exception $e){
				$send_result['result']='error';
				$send_result['data']=$e->getMessage();
			}
			print json_encode($send_result);
			$mainframe=JFactory::getApplication();
			$mainframe->close();
			die();			
		}
		}


    }
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
     * This event is triggered after update extension
     * Joomla 2.5+
     * @access public
     */
    
    public function onExtensionAfterUpdate($name, $data){
		$config = $this->getCTConfig();
		//Sending agent version	
		if(isset($config['apikey']) && trim($config['apikey']) != '' && $config['apikey'] != 'enter key'){
			self::ctSendAgentVersion($config['apikey']);
    	}
    }
    /**
     * This event is triggered after extension save their settings
     * Joomla 2.5+
     * @access public
     */        
	public function onExtensionAfterSave($name, $data)
	{
		$enabled = $data->enabled;
			$id = $this->getId('system','antispambycleantalk');
			$table = JTable::getInstance('extension');
			$table->load($id);
			$params = new JRegistry($table->params);
		if ($enabled == 1)
		{
			$new_config=json_decode($data->params);	
			$access_key = trim($new_config->apikey);
	        $params = $this->checkIsPaid($access_key, true);	
		}
		else
		{
				$params->set('ct_key_is_ok',0);
				$params->set('service_id','');
				$params->set('spam_count',0);
				$params->set('user_token','');
				$params->set('last_checked','');
		}
			$table->params = $params->toString();
			$table->store();
		
	}  
    /*
    exception for MijoShop ajax calls
    */
    public function exceptionMijoShop(){
		
    	if(@$_GET['option']=='com_mijoshop' && @$_GET['route']=='api/customer')
    		return false;
    	else
    		return true;
    	
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
		
    	$config = $this->getCTConfig();	$this->loadLanguage();	
    	if($config['tell_about_cleantalk'] == 1 && strpos($_SERVER['REQUEST_URI'],'/administrator/') === false){
    		if ((int)$config['spam_count']>0)
				$code = "<div id='cleantalk_footer_link' style='width:100%;text-align:center;'><a href='https://cleantalk.org/joomla-anti-spam-plugin-without-captcha'>Anti-spam by CleanTalk</a> for Joomla!<br>".$config['spam_count']." spam blocked</div>";
			else
				$code = "<div id='cleantalk_footer_link' style='width:100%;text-align:center;'><a href='https://cleantalk.org/joomla-anti-spam-plugin-without-captcha'>Anti-spam by CleanTalk</a> for Joomla!<br></div>";
			$documentbody = JResponse::getBody();
			$documentbody = str_replace ("</body>", $code." </body>", $documentbody);
			JResponse::setBody($documentbody);
		}

    }
    
    /**
     * Save user registration request_id
     * @access public
     * @return type
     */
    public function onBeforeCompileHead(){
		
		$user = JFactory::getUser();

    	if($user->get('isRoot'))
    	{
		
			$document = JFactory::getDocument();
			$app = JFactory::getApplication();	
			
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
			if($app->isAdmin())
			{
				$id = $this->getId('system','antispambycleantalk');
				$config = $this->getCTConfig();
				$temp_config = $this->checkIsPaid($config['apikey']);
				if (!empty($temp_config))
				{
					$table = JTable::getInstance('extension');
					$table->load($id);
					$params = $temp_config;
					$table->params = $params->toString();
					$table->store();
					$params = $params->toString();
					$config = json_decode($params,true);
				}
				if ($config['ct_key_is_ok'] === 0)
					$notice = JText::_('PLG_SYSTEM_CLEANTALK_NOTICE_APIKEY');	
				else
				{
					if(empty($config['service_id']) && !empty($config['user_token']))
						$notice = JText::sprintf('PLG_SYSTEM_CLEANTALK_NOTICE_TRIAL', $user_token);												
				}
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
						ct_notice_review_done='.(($config['show_notice_review_done'] === 1)?'true':'false').';
					
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
						ct_spamcheck_users_delconfirm = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_USERS_DELCONFIRM').'",
						ct_spamcheck_users_delconfirm_error = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_USERS_DELCONFIRM_ERROR').'",
						ct_spamcheck_comments_delconfirm = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_COMMENTS_DELCONFIRM').'",
						ct_spamcheck_comments_delconfirm_error = "'.JText::_('PLG_SYSTEM_CLEANTALK_JS_PARAM_SPAMCHECK_COMMENTS_DELCONFIRM_ERROR').'";																
				');
				
				//Admin JS and CSS
				$document->addScript(JURI::root(true)."/plugins/system/antispambycleantalk/js/ct-settings.js?".time());
				$document->addStyleSheet(JURI::root(true)."/plugins/system/antispambycleantalk/css/ct-settings.css?".time());
				
				$session = JFactory::getSession();
				$user = $session->get('user');
				$is_logged_in=false;
				
				if(is_object($user) && isset($user->id) && $user->id > 0)
					$is_logged_in = true;
				
				if(isset($config['show_notice_review']) && $config['show_notice_review'] == 1 && $is_logged_in && strpos(JFactory::getUri(), 'com_plugins&view=plugin&layout=edit&extension_id='.$id) !==false)
				{
					$document->addScriptDeclaration('var ct_show_feedback=true;');
					$document->addScriptDeclaration('var ct_show_feedback_mes="'.JText::_('PLG_SYSTEM_CLEANTALK_FEEDBACKLINK').'";');
				}
				else
					$document->addScriptDeclaration('var ct_show_feedback=false;');
				
			}
			if(isset($notice))
					JError::raiseNotice(1024, $notice);
			if ($app->isAdmin())
				return;

			$session = JFactory::getSession();
			$username = $session->get("register_username");
			$email = $session->get("register_email");
			$ct_request_id = $session->get("ct_request_id");

			if ($username != '' && $email != '') {
				self::initTables();

				$session->set("register_username", null);
				$session->set("register_email", null);
				$session->set("ct_request_id", null);

				$db = JFactory::getDBO();
				$db->setQuery("SELECT * FROM `#__users` WHERE username='" . $username . "' AND email='" . $email . "'");
				$user = $db->loadRowList();
				if (!empty($user)) {
					$user_id = $user[0][0];
					$db->setQuery("UPDATE `#__users` SET ct_request_id='" . $ct_request_id . "' WHERE id='" . $user_id . "'");
					$db->query("UPDATE `#__users` SET ct_request_id='" . $ct_request_id . "' WHERE id='" . $user_id . "'");
				}
			}
		}

    }

    /**
     * onAfterDispatch trigger - used by com_contact
     * @access public
     * @since 1.5
     */
    public function onAfterDispatch() {

        $app = JFactory::getApplication();								
        if ($app->isAdmin() && JPluginHelper::isEnabled('system', 'antispambycleantalk')){
            if ($this->ct_admin_notices == 0 && JFactory::getUser()->authorise('core.admin')) {
				$this->ct_admin_notices++;
				$this->loadLanguage();
				$config = $this->getCTConfig();
				$next_notice = true; // Flag to show one notice per time
				$notice = '';
				$key_is_ok = $config['ct_key_is_ok'];
				$user_token = $config['user_token'];
				$service_id = $config['service_id'];
				if (!$key_is_ok || (empty($service_id) && !empty($user_token)))
					$next_notice = false;
				// Notice about state of api key - trial, expired and so on.
				if($next_notice){
					// Short timeout before new check in hours - for bad accounts
					$notice_check_timeout_short = 1;
					// Long timeout before new check in hours - for good accounts
					$notice_check_timeout_long = 24;
					// Trial notice show time in minutes
					$notice_showtime = 10;

					// First try to get stored status
					$db_status = self::dbGetApikeyStatus();

					try{
						$status = unserialize($db_status['ct_status']);
					}catch(Exception $e){

					}

					// Default api key check timeout is small
					$notice_check_timeout = $notice_check_timeout_short; 

					// Good key state is stored - increase api key check timeout to long
					if(is_array($status) && isset($status['show_notice']) && $status['show_notice'] == 0)
						$notice_check_timeout = $notice_check_timeout_long; 
					// Time is greater than check timeout - need to check actual status now
					if(time() > strtotime("+$notice_check_timeout hours", $db_status['ct_changed'])){
						$status = self::checkApiKeyStatus($config['apikey'], 'notice_paid_till');
						if(isset($status) && $status !== FALSE){
							$status = $status['data'];
							if(isset($status['moderate_ip']) && $status['moderate_ip'] == 1){
								$id = $this->getId('system','antispambycleantalk');
								$table = JTable::getInstance('extension');
								$table->load($id);

								$params = new JRegistry($table->params);

								$params->set('moderate_ip', 1);
								$params->set('ip_license', $status['ip_license']);
								$table->params = $params->toString();
								$table->store();
							}
							$db_status['ct_status'] = serialize($status);
							$db_status['ct_changed'] = time();
							self::dbSetApikeyStatus($db_status['ct_status'], $db_status['ct_changed']);

						}
					}
					// Time is in notice show time - need to show notice
					if(is_array($status) && time() < strtotime("+$notice_showtime minutes", $db_status['ct_changed'])){
						// Bad apikey status is in database - need to check actual status again,
						//  because admin could change key from bad to good since last notice
						//  before api key check timeout.

						if(isset($status['show_notice']) && $status['show_notice'] == 1) {
							$new_status = self::checkApiKeyStatus($config['apikey'], 'notice_paid_till');
							if(isset($new_status) && $new_status !== FALSE){
								$status = $new_status['data'];
								self::dbSetApikeyStatus(serialize($new_status), $db_status['ct_changed']); // Save it with old time!
							}

						}
					if(isset($status['show_notice']) && $status['show_notice'] == 1 && isset($status['trial']) && $status['trial'] == 1) {
							$notice = JText::sprintf('PLG_SYSTEM_CLEANTALK_NOTICE_TRIAL', $status['user_token']);
							$next_notice = false;

						}

					}

				}

				// Place other notices here.

				// Show notice when defined
				if(!empty($notice))
					JError::raiseNotice(1024, $notice);
            }
            return;
        }
        
        $document = JFactory::getDocument();
        // Add Javascript
        $document->addScriptDeclaration($this->getJSTest(null, null, true));

        $this->ct_cookies_test();

     }


    /**
     * onAfterRoute trigger - used by com_contact
     * @access public
     * @since 1.5
     */
    public function onAfterRoute() {

        $option_cmd = JRequest::getCmd('option');
        $view_cmd = JRequest::getCmd('view');
        $task_cmd = JRequest::getCmd('task');
        $page_cmd = JRequest::getCmd('page');

        $ver = new JVersion();
        $app = JFactory::getApplication();
        //$config = $this->getCTConfig();

        if ($app->isAdmin()) {

            if ($option_cmd == 'com_users') {
                $task_cmd_remove = 'users.delete'; //2.5
                if (strcmp($ver->RELEASE, '1.5') <= 0) {
                    $task_cmd_remove = 'remove';
                }
                if ($task_cmd == $task_cmd_remove) {
                    // Sending feedback
                    if (!empty($_POST['cid'])) {
                        $db = JFactory::getDBO();
                        $db->setQuery("SELECT * FROM `#__users` WHERE id IN(" . implode(', ', $_POST['cid']) . ")");
                        $users = $db->loadAssocList();
                        if (!empty($users)) {
                            foreach ($users as $column) {
                                if (!empty($column['ct_request_id'])) {

                                    $ctFbParams = array(
                                        'moderate' => array(
                                            array('msg_hash' => $column['ct_request_id'], 'is_allow' => 0),
                                        ),
                                    );

                                    self::ctSendRequest('send_feedback', $ctFbParams);
                                }
                            }
                        }
                    }
                }
            }
        }

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
        $session = JFactory::getSession();
        $submit_time = NULL;
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $checkjs = $this->get_ct_checkjs();
            $val = $session->get($this->form_load_label);
            if ($val) {
                $submit_time = time() - (int) $val;
            }

            if (!$val && session_id() != '') {
                $this->ct_direct_post = 1;
            }
        } else {
        	if(!(isset($_GET['option']) && $_GET['option'] == 'com_extrawatch') && !(isset($_GET['checkCaptcha']) && $_GET['checkCaptcha'] == 'true') && strpos($_SERVER['REQUEST_URI'],'securimage_show.php')===false)
        	{
            	$session->set($this->form_load_label, time());
            	$session->set('cleantalk_current_page', JURI::current());
            }
        }
        /*
            Contact forms anti-spam code
        */
        $sender_email = null;
        $message = '';
        $sender_nickname = null;
        
        $post_info['comment_type'] = 'feedback';
        $post_info = json_encode($post_info);
        if ($post_info === false)
            $post_info = '';

        //
        // Rapid Contact
        // http://mavrosxristoforos.com/joomla-extensions/free/rapid-contact
        //
        if (isset($_POST['rp_email'])){
            $sender_email = $_POST['rp_email'];

            if (isset($_POST["rp_subject"]))
                $message = $_POST["rp_subject"];
            
            if (isset($_POST['rp_message']))
                $message .= ' ' . $_POST['rp_message'];
        }
        
        //
        // VTEM Contact 
        // http://vtem.net/extensions/joomla-extensions.html 
        //
        if (isset($_POST["vtem_email"])) {
            $sender_email = $_POST['vtem_email'];
            if (isset($_POST["vtem_subject"]))
                $message = $_POST["vtem_subject"];

            if (isset($_POST["vtem_message"]))
                $message .= ' ' . $_POST["vtem_message"];
            
            if (isset($_POST["vtem_name"]))
                $sender_nickname = $_POST["vtem_name"];
        }
        
        //
        // VirtueMart AskQuestion
        //
        if ($option_cmd == 'com_virtuemart' && ($task_cmd == 'mailAskquestion' || $page_cmd == 'shop.ask') && isset($_POST["email"])) {
            $sender_email = $_POST["email"];
            
            if (isset($_POST["comment"])) {
                $message = $_POST["comment"];
            }
        }
        //
        // BreezingForms 
        // http://crosstec.de/en/extensions/joomla-forms-download.html
        //
        if (isset($_POST['ff_task']) && $_POST['ff_task'] == 'submit') {
            $sender_email = '';
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
        }

        if (!$sender_email && $_SERVER['REQUEST_METHOD'] == 'POST' && !in_array($option_cmd, $this->skip_coms)){
			
			$do_test = true;
			foreach ($_POST as $k => $v) {
				if ($do_test && in_array($k, $this->skip_params)) {
					$do_test = false;
					break;
				}
			}

            $config = $this->getCTConfig();

            if ($config['general_contact_forms_test'] != '' && $do_test){
				
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
        }
        
        if (trim($sender_email) !='' && !$app->isAdmin() &&$this->exceptionMijoShop() && !$this->is_executed && !in_array($option_cmd, $this->skip_coms)){
            $result = $this->onSpamCheck(
                '',
                array(
                    'sender_email' => $sender_email, 
                    'sender_nickname' => $sender_nickname, 
                    'message' => $message
                ));

            if ($result !== true) {
                if(isset($_GET['module']) && $_GET['module'] == 'pwebcontact')
                {
                	print $this->_subject->getError();
                	die();
                }
                else
                {
                	JError::raiseError(503, $this->_subject->getError());
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
        $submit_time = $this->submit_time_test();

        $checkjs = $this->get_ct_checkjs();

        $sender_info = $this->get_sender_info();
        $sender_info_flag = json_encode($sender_info);
        if ($sender_info_flag === false) {
            $sender_info = '';
		}else{
			$js_timezone = (isset($_COOKIE['ct_timezone']) ? $_COOKIE['ct_timezone'] : '');
			$pointer_data = (isset($_COOKIE['ct_pointer_data']) ? json_decode($_COOKIE['ct_pointer_data']) : '');
			$first_key_timestamp = (isset($_COOKIE['ct_fkp_timestamp']) ? $_COOKIE['ct_fkp_timestamp'] : 0);
			$page_set_timestamp = (isset($_COOKIE['ct_ps_timestamp']) ? $_COOKIE['ct_ps_timestamp'] : 0);	
			
			$sender_info['js_timezone'] = $js_timezone;
			$sender_info['mouse_cursor_positions'] = $pointer_data;
			$sender_info['key_press_timestamp'] = $first_key_timestamp;
			$sender_info['page_set_timestamp'] = $page_set_timestamp;
			
			$sender_info = json_encode($sender_info);
		}

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

        self::getCleantalk();
        $ctResponse = self::ctSendRequest(
            'check_message', array(
                'example' => null, 
                'sender_nickname' => $data[$user_name_key],
                'sender_email' => $data[$user_email_key],
                'sender_ip' => self::$CT->ct_session_ip($_SERVER['REMOTE_ADDR']),
                'message' => $data[$subject_key] . "\n " . $data[$message_key],
                'js_on' => $checkjs,
                'submit_time' => $submit_time,
                'post_info' => $post_info,
                'sender_info' => $sender_info,
            )
        );
        
        $app = JFactory::getApplication();
        if (!empty($ctResponse) && is_array($ctResponse)) {
            if ($ctResponse['errno'] != 0) {
                $this->sendAdminEmail("CleanTalk. Can't verify feedback message!", $ctResponse['comment']);
            } else {
                if ($ctResponse['allow'] == 0) {
                    $session->set($this->form_load_label, time()); // update session 'formtime'
                    $res_str = $ctResponse['comment'];
                    $app->setUserState('com_contact.contact.data', $data);  // not used in 1.5 :(
                    $stub = JRequest::getString('id');
                    // Redirect back to the contact form.
                    // see http://docs.joomla.org/JApplication::redirect/11.1 - what does last param mean?
                    // but it works! AZ
                    $app->redirect(JRoute::_('index.php?option=com_contact&view=contact&id=' . $stub, false), $res_str, 'warning');
                    return new Exception($res_str); // $res_str not used in com_contact code - see source :(
                }
            }
        }
        if(!(isset($_POST['itemName']) && $_POST['itemName'] == 'reginfo') && !(isset($_POST['option']) && $_POST['option'] == 'com_breezingforms'))
        {
        	$session->clear($this->form_load_label); // clear session 'formtime'
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
        $submit_time = $this->submit_time_test();

        // set new time because onJCommentsFormAfterDisplay worked only once
        // and formtime in session need to be renewed between ajax posts
        $session->set($this->form_load_label, time());

        $checkjs = $this->get_ct_checkjs();

        $sender_info = $this->get_sender_info();
        
		$js_timezone = (isset($_COOKIE['ct_timezone']) ? $_COOKIE['ct_timezone'] : '');
		$pointer_data = (isset($_COOKIE['ct_pointer_data']) ? json_decode($_COOKIE['ct_pointer_data']) : '');
		$first_key_timestamp = (isset($_COOKIE['ct_fkp_timestamp']) ? $_COOKIE['ct_fkp_timestamp'] : 0);
		$page_set_timestamp = (isset($_COOKIE['ct_ps_timestamp']) ? $_COOKIE['ct_ps_timestamp'] : 0);	
		
		$sender_info['js_timezone'] = $js_timezone;
		$sender_info['mouse_cursor_positions'] = $pointer_data;
		$sender_info['key_press_timestamp'] = $first_key_timestamp;
		$sender_info['page_set_timestamp'] = $page_set_timestamp;
		
        $sender_info = json_encode($sender_info);
        if ($sender_info === false) {
            $sender_info = '';
        }
        
        $post_info['comment_type'] = 'jcomments_comment'; 
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

                self::getCleantalk();
                $ctResponse = self::ctSendRequest(
                    'check_message', array(
                        'example' => $example,
                        'message' =>preg_replace('/\s+/', ' ',str_replace("<br />", " ", $comment->comment)),
                        'sender_nickname' => $comment->name,
                        'sender_email' => $comment->email,
                        'sender_ip' => self::$CT->ct_session_ip($_SERVER['REMOTE_ADDR']),
                        'js_on' => $checkjs,
                        'submit_time' => $submit_time,
                        'sender_info' => $sender_info,
                        'post_info' => $post_info,
                    )
                );
                if (!empty($ctResponse) && is_array($ctResponse)) {
                    if ($ctResponse['allow'] == 0) {
                        JCommentsAJAX::showErrorMessage($ctResponse['comment'], 'comment');
                        $comment->published = false;
                        if ($config['jcomments_unpublished_nofications'] != '') {
                            JComments::sendNotification($comment, true);
                        }
                        return false;
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
        $submit_time = $this->submit_time_test();

        $checkjs = $this->get_ct_checkjs();

        $sender_info = $this->get_sender_info();
        $sender_info_flag = json_encode($sender_info);
        if ($sender_info_flag === false) {
            $sender_info = '';
		}else{
			$js_timezone = (isset($_COOKIE['ct_timezone']) ? $_COOKIE['ct_timezone'] : '');
			$pointer_data = (isset($_COOKIE['ct_pointer_data']) ? json_decode($_COOKIE['ct_pointer_data']) : '');
			$first_key_timestamp = (isset($_COOKIE['ct_fkp_timestamp']) ? $_COOKIE['ct_fkp_timestamp'] : 0);
			$page_set_timestamp = (isset($_COOKIE['ct_ps_timestamp']) ? $_COOKIE['ct_ps_timestamp'] : 0);	
			
			$sender_info['js_timezone'] = $js_timezone;
			$sender_info['mouse_cursor_positions'] = $pointer_data;
			$sender_info['key_press_timestamp'] = $first_key_timestamp;
			$sender_info['page_set_timestamp'] = $page_set_timestamp;

			$sender_info = json_encode($sender_info);
		}

        self::getCleantalk();
        $ctResponse = self::ctSendRequest(
                'check_newuser', array(
                    'sender_ip' => self::$CT->ct_session_ip($_SERVER['REMOTE_ADDR']),
                    'sender_email' => $post_email,
                    'sender_nickname' => $post_username,
                    'submit_time' => $submit_time,
                    'js_on' => $checkjs,
                    'sender_info' => $sender_info 
                )
        );

        if (!empty($ctResponse) && is_array($ctResponse)) {
            if ($ctResponse['allow'] == 0) {
                if ($ctResponse['errno'] != 0) {
                    $this->sendAdminEmail("CleanTalk plugin", $ctResponse['comment']);
                } else {
                    $session->set('ct_register_form_data', $post);

                    $app = & JFactory::getApplication();
                    $app->enqueueMessage($ctResponse['comment'], 'error');

                    $uri = & JFactory::getUri();
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
                $comment = self::$CT->addCleantalkComment("", $ctResponse['comment']);
                $hash = self::$CT->getCleantalkCommentHash($comment);

                $session->set('register_username', $post_username);
                $session->set('register_email', $post_email);
                $session->set('ct_request_id', $hash);
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


    /**
     * Interface to XML RPC server
     * $mehod - method name
     * $params - array of XML params
     * return XML RPS server response
     */
   private function ctSendAgentVersion($apikey)
    {
    	self::getCleantalk();
		if (self::getCleantalk() == null)
			return;
        $ctFbParams['feedback'] = '0:' . self::ENGINE;
        defined('_JEXEC') or die('Restricted access');
        if(!defined('DS')){
            define('DS', DIRECTORY_SEPARATOR);
        }
        $ct_request = new CleantalkRequest;
        
        foreach ($ctFbParams as $k => $v) {
            $ct_request->$k = $v;
        }
        $ct_request->auth_key = $apikey;
        $ct_request->agent = self::ENGINE; 
        $config = self::dbGetServer();
        $result = NULL;

        self::$CT->work_url = $config['ct_work_url'];
        self::$CT->server_ttl = $config['ct_server_ttl'];
        self::$CT->server_changed = $config['ct_server_changed'];
        $result = self::$CT->sendFeedback($ct_request);
                if (self::$CT->server_change) {
            self::dbSetServer(self::$CT->work_url, self::$CT->server_ttl, time());
        }
        return $result;
    }
    private function ctSendRequest($method, $params) {
        self::getCleantalk();

        switch ($method) {
            case 'check_message':
                break;
            case 'send_feedback':
                $feedback = array();
                foreach ($params['moderate'] as $msgFeedback)
                    $feedback[] = $msgFeedback['msg_hash'] . ':' . intval($msgFeedback['is_allow']);

                $feedback = implode(';', $feedback);

                $params['feedback'] = $feedback;
                break;
            case 'check_newuser':
                break;
            case 'get_api_key':
            	break;
            default:
                return NULL;
        }

        $config = $this->getCTConfig();

        defined('_JEXEC') or die('Restricted access');
        if(!defined('DS')){
            define('DS', DIRECTORY_SEPARATOR);
        }
        		
        $ct_request = new CleantalkRequest;
        
        foreach ($params as $k => $v) {
            $ct_request->$k = $v;
        }
        $ct_request->auth_key = $config['apikey'];
        $ct_request->agent = self::ENGINE; 

        $config = self::dbGetServer();
        $result = NULL;

        self::$CT->work_url = $config['ct_work_url'];
        self::$CT->server_ttl = $config['ct_server_ttl'];
        self::$CT->server_changed = $config['ct_server_changed'];
        
        switch ($method) {
            case 'check_message':
                $result = self::$CT->isAllowMessage($ct_request);
                break;
            case 'send_feedback':
                $result = self::$CT->sendFeedback($ct_request);
                break;
            case 'check_newuser':
                $result = self::$CT->isAllowUser($ct_request);
                break;
            default:
                return NULL;
        }
        if (self::$CT->server_change) {
            self::dbSetServer(self::$CT->work_url, self::$CT->server_ttl, time());
        }

        // Result should be an associative array 
        $result = json_decode(json_encode($result), true);


        if(isset($result['errno']) && intval($result['errno'])!=0 && intval($ct_request->js_on)==1)
        {
        	$result['allow'] = 1;
        	$result['errno'] = 0;
        }
        if(isset($result['errno']) && intval($result['errno'])!=0 && intval($ct_request->js_on)!=1)
        {
        	$result['allow'] = 0;
        	$result['spam'] = 1;
        	$result['stop_queue'] = 1;
        	$result['comment']='Forbidden. Please, enable Javascript.';
        	$result['errno'] = 0;
        }
        return $result;
    }

    /**
     * Cleantalk instance
     * @return Cleantalk instance
     */
    private function getCleantalk() {
		//disable calls on update
		if ((isset($_GET['option']) && $_GET['option'] == 'com_installer') && (isset($_GET['view']) && $_GET['view'] == 'update'))
			return;
        if (!isset(self::$CT)) {

            $config = $this->getCTConfig();

            defined('_JEXEC') or die('Restricted access');
            if(!defined('DS')){
                define('DS', DIRECTORY_SEPARATOR);
            }
            
            self::$CT = new Cleantalk;
            self::$CT->server_url = $config['server'];
        }

        return self::$CT;
    }

    /**
     * Interface to get CT options 
     * @return array 
     */
    private function getCTConfig() {
        $plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
            
        $config['apikey'] = ''; 
        $config['ct_key_is_ok'] = 0;
        $config['server'] = '';
        $config['jcomments_unpublished_nofications'] = '';
        $config['general_contact_forms_test'] = '';
        $config['relevance_test'] = '';
        $config['user_token'] = '';
        $config['service_id'] ='';
        $config['js_keys'] = '';
        $config['spam_count'] = 0;
        $config['moderate_ip'] =0;
        $config['ip_license'] =0;
        $config['show_notice_review'] = 0;
        $config['show_notice_review_done'] = 0;
        $config['last_checked'] = 0;
  		$jreg = new JRegistry($plugin->params);
		$config['apikey'] = trim($jreg->get('apikey', ''));
		$config['ct_key_is_ok'] = $jreg->get('ct_key_is_ok',0);
		$config['server'] = $jreg->get('server', '');
		$config['jcomments_unpublished_nofications'] = $jreg->get('jcomments_unpublished_nofications', '');
		$config['general_contact_forms_test'] = $jreg->get('general_contact_forms_test', '');
		$config['relevance_test'] = $jreg->get('relevance_test', '');
		$config['user_token'] = $jreg->get('user_token', '');
		$config['service_id'] = $jreg->get('service_id','');
		$config['spam_count'] = $jreg->get('spam_count',0);
		$config['moderate_ip'] = $jreg->get('moderate_ip',0);
		$config['ip_license'] = $jreg->get('ip_license',0);
		$config['tell_about_cleantalk'] = $jreg->get('tell_about_cleantalk', '');
		$config['js_keys'] = $jreg->get('js_keys','');
		$config['show_notice_review_done'] = $jreg->get('show_notice_review_done',0);
		$config['show_notice_review'] = $jreg->get('show_notice_review',0);
		$config['last_checked'] = $jreg->get('last_checked',0);
        return $config;
    }


    /**
     * Cleantalk tables creator
     * @return bool
     */
    private function initTables() {
        $db = JFactory::getDBO();
        $prefix = $db->getPrefix();
        $arrTables = $db->getTableList();

        $db->setQuery("SHOW COLUMNS FROM `#__users`");
        $users_columns = $db->loadRowList();
        $field_presence = false;

        foreach ($users_columns as $column) {
            if ($column[0] == 'ct_request_id' || $column[0] == 'ct_marked_as_spam') {
                $field_presence = true;
            }
        }

        if (!$field_presence) {
            $db->setQuery("ALTER TABLE `#__users` ADD ct_request_id char(32) NOT NULL DEFAULT ''");
            $db->query();
            $db->setQuery("ALTER TABLE `#__users` ADD ct_marked_as_spam int NOT NULL DEFAULT 0");
            $db->query();
        }

        if (!empty($arrTables)) {
            $db->setQuery("SELECT count(*) FROM #__ct_curr_server");
            $row = $db->loadRow();
            if ($row[0] == 0) {
                $db->setQuery(
                        "INSERT  " .
                        "INTO #__ct_curr_server (ct_work_url,ct_server_ttl,ct_server_changed ) " .
                        "VALUES ('', 0, 0)");
                if ($db->query() !== FALSE)
                    self::$tables_ready = TRUE;
            }else {
                self::$tables_ready = TRUE;
            }

            if(self::$tables_ready){
                $db->setQuery("SELECT count(*) FROM #__ct_apikey_status");
                $row = $db->loadRow();
                if ($row[0] == 0) {
                    $db->setQuery(
                        "INSERT  " .
                        "INTO #__ct_apikey_status (ct_status,ct_changed ) " .
                        "VALUES ('', 0)");
                    if ($db->query() !== FALSE)
                        self::$tables_ready = TRUE;
                }else {
                    self::$tables_ready = TRUE;
                }
            }
        }
        return self::$tables_ready;
    }

    /**
     * Current server getter
     * @return array
     */
    private function dbGetServer() {
        if (!self::$tables_ready) {
            self::initTables();
        }
        $db = JFactory::getDBO();
        $db->setQuery("SELECT ct_work_url,ct_server_ttl,ct_server_changed FROM #__ct_curr_server ORDER BY id LIMIT 1");
        $row = $db->loadAssoc();
        return $row;
    }

    /**
     * Current server setter
     * $ct_work_url
     * $ct_server_ttl
     * $ct_server_changed
     * @return null
     */
    private function dbSetServer($ct_work_url, $ct_server_ttl, $ct_server_changed) {
        if (!self::$tables_ready) {
            self::initTables();
        }
        $db = JFactory::getDBO();
        $db->setQuery(
                "UPDATE #__ct_curr_server " .
                " SET " .
                "ct_work_url = '" . $ct_work_url . "', " .
                "ct_server_ttl = " . $ct_server_ttl . ", " .
                "ct_server_changed = " . $ct_server_changed);
        $db->query();
    }
  
    /**
     * Current apikey status getter
     * @return array
     */
    private function dbGetApikeyStatus() {
        if (!self::$tables_ready) {
            self::initTables();
        }
        $db = JFactory::getDBO();
        $db->setQuery("SELECT ct_status,ct_changed FROM #__ct_apikey_status ORDER BY id LIMIT 1");
        $row = $db->loadAssoc();
        return $row;
    }

    /**
     * Current apikey status setter
     * $ct_status
     * $ct_changed
     * @return null
     */
    private function dbSetApikeyStatus($ct_status, $ct_changed) {
        if (!self::$tables_ready) {
            self::initTables();
        }
        $db = JFactory::getDBO();
        $db->setQuery(
                "UPDATE #__ct_apikey_status " .
                " SET " .
                "ct_status = '" . $ct_status . "', " .
                "ct_changed = " . $ct_changed);
        $db->query();
    }
  
    /**
    * Get value of $ct_checkjs
    * JavaScript avaibility test.
    * @return null|0|1
    */
    private function get_ct_checkjs($cookie_check = true){

        if ($cookie_check) {
            $data = $_COOKIE; 
        } else {
            $data = $_POST;
        }

        $checkjs = null;
        if (isset($data['ct_checkjs'])) {
            $checkjs_valid = $this->cleantalk_get_checkjs_code();
            if (!$checkjs_valid)
                return $checkjs;

            if (preg_match("/$checkjs_valid/", $data['ct_checkjs'])) {
                $checkjs = 1;
            } else {
                $checkjs = 0;
            }
        }

        $option_cmd = JRequest::getCmd('option');
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
    private function getJSTest($needle = null, $after = false, $cookie_check = false) {
        try {
            $ct_checkjs_key = $this->cleantalk_get_checkjs_code();
        } catch (Exception $e) {
            $ct_checkjs_key = 1;
        }
        
        $session = JFactory::getSession();
        $value = $this->cleantalk_get_checkjs_code();
        /*
            JavaScript validation via Cookies
        */
        if ($cookie_check) {
            $field_name = 'ct_checkjs';
        $get_funcs = file_get_contents(dirname(__FILE__) . DS. "js". DS. "ct-functions.js");
        $html = str_replace("{value}", $value, $get_funcs);
		$html = sprintf($html, $field_name, $ct_checkjs_key);
            return $html;
        }

        $field_id = 'ct_checkjs_' . md5(rand(0, 1000));

        $str = '<input type="hidden" id="' . $field_id . '" name="ct_checkjs" value="' . self::CT_CHECKJS_DEF . '" />'. "\n";
        $str .= '<script type="text/javascript">'. "\n";
        $str .= '// <![CDATA['. "\n";
        $str .= 'document.getElementById("'. $field_id .'").value = document.getElementById("'. $field_id .'").value.replace(/^' . self::CT_CHECKJS_DEF . '$/, "' . $ct_checkjs_key . '");'. "\n";
        $str .= '// ]]>'. "\n";
        $str .= '</script>'. "\n";
        
        $document = JFactory::getDocument();
        $content = $document->getBuffer('component');
        
        //
        // Code position 
        //
        if ($after) {
            $str = '$1 ' . $str;
        } else {
            $str = $str . ' $1'; 
        }
        
        $newContent = preg_replace($needle, $str, $content);
        $document->setBuffer($newContent, 'component');
        
        return null;
    } 
    /**
     * Valids email 
     * @return bool 
     * @since 1.5
     */
    private function validEmail($string) {
        if (!isset($string) || !is_string($string)) {
            return false;
        }
        

        return preg_match("/^\S+@\S+$/i", $string); 
    }
    
    /**
     * Validate form submit time 
     *
     */
    private function submit_time_test() {
        $session = JFactory::getSession();
        $val = $session->get($this->form_load_label);
        if ($val) {
            $submit_time = time() - (int) $val;
        } else {
            $submit_time = NULL;
        }

        return $submit_time;
    }
    
    /**
     * Inner function - Default data array for senders 
     * @return array 
     */
    private function get_sender_info() {
        $session = JFactory::getSession();
        
        // Raw data to validated JavaScript test in the cloud
        $checkjs_data_cookies = null; 
        if (isset($_COOKIE['ct_checkjs'])) {
            $checkjs_data_cookies = $_COOKIE['ct_checkjs'];
        }
				
        $checkjs_data_post = null; 
        if (count($_POST) > 0) {
			foreach ($_POST as $k => $v) {
				if (preg_match("/^ct_check.*/", $k)) {
	        		$checkjs_data_post = $v; 
				}
			}
		}
        
        $config = $this->getCTConfig();
        
        $sender_info = array(
            'REFFERRER' => @$_SERVER['HTTP_REFERER'],
            'USER_AGENT' => @$_SERVER['HTTP_USER_AGENT'],
            'direct_post' => $this->ct_direct_post,
            'cookies_enabled' => $this->ct_cookies_test(true), 
            'checkjs_data_post' => $checkjs_data_post, 
            'checkjs_data_cookies' => $checkjs_data_cookies, 
            'ct_options'=>json_encode($config)
        );
        return $sender_info;
    }

    /**
     * Cookies test for sender 
     * @return null|0|1;
     */
    private function ct_cookies_test ($test = false) {
        $cookie_label = 'ct_cookies_test';
        $secret_hash = $this->cleantalk_get_checkjs_code();

        $result = null;
        if (isset($_COOKIE[$cookie_label])) {
            if ($_COOKIE[$cookie_label] == $secret_hash) {
                $result = 1;
            } else {
                $result = 0;
            }
        } else {
            setcookie($cookie_label, $secret_hash, 0, '/');

            if ($test) {
                $result = 0;
            }
        }
        
        return $result;
    }

	 /**
	 * Does the CleanTalk Magic and Throws error message if message is not allowed
	 * @param	string	$context	The context of the content being passed to the plugin. Usually component.view (example: com_contactenhanced.contact)
	 * @param	array	$data		Containing all required data ($sender_email, $sender_nickname,$message)
	 * @return 	boolean True if passes validation OR false if it fails
	 */
	private function onSpamCheck($context='', $data){
		if($this->check_url_exclusions())
		{
			return false;
		}
		// Converts $data Array into an Object
		$obj = new JObject($data);
        
        $ver = new JVersion();
        if (strcmp($ver->RELEASE, '1.5') <= 0) {
            foreach ($data as $k => $v) {
                $obj->set($k, $v);
            }
        } else {
            // sets 'sender_email' ONLY if not already set. Also checks to see if 'email' was not provided instead
            $obj->def('sender_email',$obj->get('email',null));
            // sets 'sender_nickname' ONLY if not already set. Also checks to see if 'name' was not provided instead
            $obj->def('sender_nickname',$obj->get('name',null));
            // sets 'message' ONLY if not already set. Also checks to see if 'comment' was not provided instead
            $obj->def('message',$obj->get('comment',null));
        }

		$session = JFactory::getSession();
		$submit_time = $this->submit_time_test();
	
		$checkjs = $this->get_ct_checkjs();
	
        $sender_info = $this->get_sender_info();
        $sender_info_flag = json_encode($sender_info);
        if ($sender_info_flag === false) {
            $sender_info = '';
		}else{
			$js_timezone = (isset($_COOKIE['ct_timezone']) ? $_COOKIE['ct_timezone'] : '');
			$pointer_data = (isset($_COOKIE['ct_pointer_data']) ? json_decode($_COOKIE['ct_pointer_data']) : '');
			$first_key_timestamp = (isset($_COOKIE['ct_fkp_timestamp']) ? $_COOKIE['ct_fkp_timestamp'] : 0);
			$page_set_timestamp = (isset($_COOKIE['ct_ps_timestamp']) ? $_COOKIE['ct_ps_timestamp'] : 0);	
			
			$sender_info['js_timezone'] = $js_timezone;
			$sender_info['mouse_cursor_positions'] = $pointer_data;
			$sender_info['key_press_timestamp'] = $first_key_timestamp;
			$sender_info['page_set_timestamp'] = $page_set_timestamp;

			$sender_info = json_encode($sender_info);
		}

		// gets 'comment_type' from $data. If not se it will use 'event_message'
		$post_info['comment_type'] = $obj->get('comment_type','event_message');
		$post_info['post_url'] = $session->get('cleantalk_current_page');
		$post_info = json_encode($post_info);
		if ($post_info === false) {
			$post_info = '';
		}
	
		self::getCleantalk();
		$ctResponse = self::ctSendRequest(
				'check_message', array(
						'message' => $obj->get('message'),
						'sender_email' => $obj->get('sender_email'),
						'sender_ip' => self::$CT->ct_session_ip($_SERVER['REMOTE_ADDR']),
						'sender_nickname' => $obj->get('sender_nickname'),
						'js_on' => $checkjs,
						'post_info' => $post_info,
						'submit_time' => $submit_time,
                        'sender_info' => $sender_info 
				)
		);

		if (!empty($ctResponse['allow']) AND $ctResponse['allow'] == 1 || $ctResponse['errno']!=0 && $checkjs==1) {
			return true;
		} else {
			// records error message in dispatcher (and let the event caller handle)
			$this->_subject->setError($ctResponse['comment']);
			return false;
		}
	}
        
        /**
	 * Checks current state on CleanTalk API key - wrong, trial, expired and so on.
	 * @param	string	$apikey	API key
	 * @param	string	$method	Checking method ('notice_paid_till', 'notice_validate_key', ...)
	 * @return 	array|boolean Assoc array or FALSE
	 */
        private function checkApiKeyStatus($apikey, $method){
	    if (function_exists('curl_init')) {
                //$url = 'https://cleantalk.org/app_notice';
                $url = 'https://api.cleantalk.org';
                $server_timeout = 10;

                $data = array();
                $data['auth_key'] = $apikey;
                $data['method_name'] = $method;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_TIMEOUT, $server_timeout);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, Array('Connection: Close'));
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

                // receive server response ...
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // resolve 'Expect: 100-continue' issue
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));

                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $result = curl_exec($ch);
                curl_close($ch);

                if ($result) {
                    $result = json_decode($result, true);
                    if(isset($result)){
                        return $result;
                    }
                }
            }
            return FALSE;
        }

    /**
	 *  Generage secret key to protect website against spam bots. 
	 * @return null|bool	
	 */
    private function swf_get_key($sender_ip = '', $apikey = '') {
        return md5($sender_ip . $apikey); 
    }
    
    /**
	 *  Checks necessity to run SpamFireWall for a visitor 
	 * @return null|bool	
	 */
    private function swf_do_check($ct_apikey, $sfw_test_ip = null) {
        $do_check = true;

        self::getCleantalk(); 
        $sender_ip = self::$CT->ct_session_ip($_SERVER['REMOTE_ADDR']);
        if ($sfw_test_ip) {
            $sender_ip = $sfw_test_ip;
        }

        if (isset($_COOKIE[$this->sfw_cookie_lable])) {
            $sfw_key = $this->swf_get_key($sender_ip, $ct_apikey);
            if ($_COOKIE[$this->sfw_cookie_lable] == $sfw_key) {
                $do_check = false;
            }
        }

        return $do_check; 
    }
    
    /**
	 * Initialize CleanTalk SpamFireWall option. 
	 * @return null|bool	
	 */
    private function swf_init($ct_apikey, $sfw_test_ip = null) {
        self::getCleantalk();
        $sender_ip = self::$CT->ct_session_ip($_SERVER['REMOTE_ADDR']); 
        if (!$sender_ip) {
            return false;
        }
        
        if ($sfw_test_ip) {
            $sender_ip = $sfw_test_ip;
        }
        /*$plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
        $jparam = new JRegistry($plugin->params);
        $sfw_min_mask = $jparam->get('sfw_min_mask', 0);
        $sfw_max_mask = $jparam->get('sfw_max_mask', 0);

        $base = ip2long('255.255.255.255');
        
        $sfw_min_mask_bit = 32 - log(($sfw_min_mask ^ $base)+1,2);
        $sfw_max_mask_bit = 32 - log(($sfw_max_mask ^ $base)+1,2);

        $nets = array();
        for ($i = $sfw_max_mask_bit; $i >= $sfw_min_mask_bit; $i--) {
            $mask = ip2long(long2ip(-1 << (32 - (int)$i)));
            $network = ip2long($sender_ip) & $mask;
            $nets[$network] = true; 
        }
        
        if (count($nets) == 0) {
            return false;
        }
        $sql_list = implode(",", array_keys($nets));*/
        
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select($db->quoteName(array('network')));
        $query->from($db->quoteName($this->sfw_table_name));
        //$query->where($db->quoteName('network') . ' in (' . $sql_list . ')');
        $query->where($db->quoteName('network') . ' = '.sprintf("%u", ip2long($sender_ip)). '& mask');
        $query->setlLimit(1);
        $db->setQuery($query);
        $row = $db->loadRow();
            
        $sfw_key = $this->swf_get_key($sender_ip, $ct_apikey);
        
		$id=$this->getId('system','antispambycleantalk');
		
		$component = JRequest::getCmd( 'component' );
		$table = JTable::getInstance('extension');
		$table->load($id);
		if($table->element=='antispambycleantalk')
		{
			$plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
			$jparam = new JRegistry($plugin->params);
			$sfw_log = (array)$jparam->get('sfw_log', 0);
			if(!is_array($sfw_log))
			{
				$sfw_log = Array();
			}
		}

        if (isset($row[0]) && preg_match("/^\d+$/", $row[0])) {
            header('HTTP/1.0 403 Forbidden');
            
            if(!isset($sfw_log[$sender_ip]))
			{
				$sfw_log[$sender_ip]=new stdClass();
				$sfw_log[$sender_ip]->all=1;
				$sfw_log[$sender_ip]->allow=0;
				$sfw_log[$sender_ip]->datetime=time();
			}
			else
			{
				$sfw_log[$sender_ip]->all++;
			}

            $sfw_reload_timeout = $jparam->get('sfw_reload_timeout', 3);
            $html_file = file_get_contents(dirname(__FILE__) . '/spamfirewall.html');
            echo sprintf($html_file, 
                $sfw_reload_timeout * 1000,
                $this->sfw_cookie_lable,
                $sfw_key,
                $sender_ip, 
                $sender_ip,
                $sfw_reload_timeout
                );
            
            /*$sfw_log[$sender_ip]->all++;
            $sfw_log[$sender_ip]->block++;*/
            $params   = new JRegistry($table->params);
			$params->set('sfw_log',$sfw_log);
			$table->params = $params->toString();
			$table->store();
            exit; 
        }
        else
        {
        	//$sfw_log[$sender_ip]->all++;
        	//
	        // Setup secret key if the visitor doesn't exit in sfw_networks.
	        //
	        setcookie($this->sfw_cookie_lable, $sfw_key, 0, '/');
        }
        
        $params   = new JRegistry($table->params);
		$params->set('sfw_log',$sfw_log);
		$table->params = $params->toString();
		$table->store();
        
        

        return null;
    }
    
    private function update_sfw_db_networks($ct_apikey)
    {
        $app = JFactory::getApplication();             
        $save_params = array();
        $plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
        $jparam = new JRegistry($plugin->params);
        $sfw_last_check = $jparam->get('sfw_last_check', 0);  
        $prefix = $app->getCfg('dbprefix');
        $sfw_table_name_full = preg_replace('/^(#__)/', $prefix, $this->sfw_table_name);               
        $db = JFactory::getDbo();
		$query="CREATE TABLE IF NOT EXISTS ".$sfw_table_name_full." (
					  `network` int(11) unsigned NOT NULL,
					  `mask` int(11) unsigned NOT NULL,
					  KEY `network` (`network`)
					) ENGINE=MyISAM DEFAULT CHARSET=latin1;";
		$db->setQuery($query);
		$db->execute();
        $tables = JFactory::getDbo()->getTableList();                
        $sfw_nets = null;
        $ct_r = null;
        $ct_rd = null;
        $min_mask = pow(2, 32); 
        $max_mask = 0;
        if (in_array($sfw_table_name_full, $tables)) {
            self::getCleantalk(); 
            $ct_r = self::$CT->get_2s_blacklists_db($ct_apikey);
            if ($ct_r) {
                $ct_rd = json_decode($ct_r, true); 
            }
            if (isset($ct_rd['data'])) {
                $sfw_nets = $ct_rd['data'];
            }                         
        }
        if ($sfw_nets) 
        {
            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->delete($db->quoteName($this->sfw_table_name));
            $db->setQuery($query);
            $result = $db->execute();
            if ($result === true) {
              	// Create a new query object.
                $query = $db->getQuery(true);                        
                // Insert columns.
                $columns = array('network', 'mask');                         
                // Prepare the insert query.
                $query->insert($db->quoteName($this->sfw_table_name));
				$query->columns($db->quoteName($columns));
                $values = null;
                foreach ($sfw_nets as $v) {
                    $values[] = implode(',', $v);  
                    if ($v[1] <= $min_mask) {
                        $min_mask = $v[1];
                    }
                    if ($v[1] >= $max_mask) {
                        $max_mask = $v[1];
                    }
                }
                $query->values($values);
                $db->setQuery($query);
                $result = $db->execute();
            }
        }                                
        $save_params['sfw_last_check'] = time();
        $save_params['sfw_min_mask'] = $min_mask;
        $save_params['sfw_max_mask'] = $max_mask;
	    if (count($save_params)) {
	        $id = $this->getId('system','antispambycleantalk');
	        $table = JTable::getInstance('extension');
	        $table->load($id);	            
	        $params = new JRegistry($table->params);
	        foreach ($save_params as $k => $v) {
	            $params->set($k, $v);
	        }
	        $table->params = $params->toString();
	        $table->store();
	    }        
	}   
}
