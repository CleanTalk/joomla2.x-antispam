<?php

/**
 * CleanTalk joomla plugin
 *
 * @version 3.3
 * @package Cleantalk
 * @subpackage Joomla
 * @author CleanTalk (welcome@cleantalk.org) 
 * @copyright (C) 2015 Сleantalk team (http://cleantalk.org)
 * @license GNU/GPL: http://www.gnu.org/copyleft/gpl.html
 *
 */

defined('_JEXEC') or die('Restricted access');
jimport('joomla.plugin.plugin');
jimport('joomla.application.application');
jimport('joomla.application.component.helper');


class plgSystemAntispambycleantalk extends JPlugin {
    /**
     * Plugin version string for server
     */
    const ENGINE = 'joomla-33';
    
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
     * Constructor
     * @access public
     * @param $subject
     * @param $config
     * @return void
     */
    public function plgSystemAntispambycleantalk (&$subject, $config) {
        parent::__construct($subject, $config);
    }
    
    /*
    * Send request to CleanTalk server
    */
    
    private function sendRequest($url,$data,$isJSON)
    {
    	$result=null;
    	if(!$isJSON)
		{
			$data=http_build_query($data);
		}
		else
		{
			$data= json_encode($data);
		}
    	if (function_exists('curl_init') && function_exists('json_decode'))
		{
		
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			
			// receive server response ...
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			// resolve 'Expect: 100-continue' issue
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
			
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			
			$result = curl_exec($ch);
			curl_close($ch);
		}
		else
		{
			$opts = array(
			    'http'=>array(
			        'method'=>"POST",
			        'content'=>$data)
			);
    		$context = stream_context_create($opts);
    		$result = @file_get_contents($url, 0, $context);
		}
		return $result;
    }
    
    /*
    * Get id of CleanTalk extension
    */
    
	function getId($folder,$name)
	{
		$db=JFactory::getDBO();
		if(!version_compare(JVERSION, '3', 'ge')) //joomla 2.5
    	{
			$sql='SELECT extension_id FROM #__extensions WHERE folder ="'.$db->getEscaped($folder).'" AND element ="'.$db->getEscaped($name).'"';
			$db->setQuery($sql);
		}
		else
		{
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
		{
			return 0;
		}
		else
		{
			return (int)$plg->extension_id;
		}
	}
	
	/*
	* Checks if auth_key is paid or not
	*/
    
	private function checkIsPaid()
	{
    	$id=0;
    	$id=$this->getId('system','antispambycleantalk');

    	if($id!==0)
    	{
    		$component = JRequest::getCmd( 'component' );
			$table = JTable::getInstance('extension');
    		$table->load($id);
    		if($table->element=='antispambycleantalk')
    		{
    			$plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
				$jparam = new JRegistry($plugin->params);
				$last_checked=$jparam->get('last_checked', 0);
				$new_checked=time();
				$last_status=intval($jparam->get('last_status', -1));
				$api_key=$jparam->get('apikey', '');
				$show_notice=$jparam->get('show_notice', 0);
				if($api_key!=''&&$api_key!='enter key')
				{
					$new_status=$last_status;
					if($new_checked-$last_checked>10)
					{
						$url = 'https://api.cleantalk.org';
			    		$dt=Array(
			    			'auth_key'=>$api_key,
			    			'method_name'=> 'get_account_status');
			    		$result=$this->sendRequest($url,$dt,false);
			    		if($result!==null)
			    		{
			    			$result=json_decode($result);
			    			if(isset($result->data)&&isset($result->data->paid))
			    			{
			    				$new_status=intval($result->data->paid);
			    				if($last_status!=1&&$new_status==1)
			    				{
			    					$show_notice=1;
			    					//set notice
			    				}
			    			}
			    		}
			    		$params   = new JRegistry($table->params);
						$params->set('last_checked',$new_checked);
						$params->set('last_status',$new_status);
						$params->set('show_notice',$show_notice);
						$table->params = $params->toString();
						$table->store();
					}
				}
    		}
    	}
    }
    
    /**
	 * Inner function - Finds and returns pattern in string
	 * @return null|bool
	 */
    function getDataFromSubmit($value = null, $field_name = null) {
	    if (!$value || !$field_name || !is_string($value)) {
	        return false;
	    }
	    if (preg_match("/[a-z0-9_\-]*" . $field_name. "[a-z0-9_\-]*$/", $value)) {
	        return true;
	    }
	}
    
    /*
	* Get data from submit recursively
	*/
	
	public function getFieldsAny(&$email,&$message,&$nickname,&$subject, &$contact,$arr)
	{
		$skip_params = array(
		    'ipn_track_id', // PayPal IPN #
		    'txn_type', // PayPal transaction type
		    'payment_status', // PayPal payment status
	    );
		foreach($arr as $key=>$value)
		{
			if(!is_array($value)&&!is_object($value))
			{
				if (in_array($key, $skip_params) || preg_match("/^ct_checkjs/", $key)) {
	                $contact = false;
	            }
				if ($email === '' && preg_match("/^\S+@\S+\.\S+$/", $value))
		    	{
		            $email = $value;
		        }
		        else if ($nickname === '' && $this->getDataFromSubmit($value, 'name'))
		    	{
		            $nickname = $value;
		        }
		        else if ($subject === '' && $this->getDataFromSubmit($value, 'subject'))
		    	{
		            $subject = $value;
		        }
		        else
		        {
		        	$message.="$value\n";
		        }
			}
			else
			{
				$this->getFieldsAny($email, $message, $nickname, $subject, $contact, $value);
			}
		}
	}
    
   
    /**
     * This event is triggered after Joomla initialization
     * Joomla 1.5
     * @access public
     */
    
    public function onAfterInitialise()
    {
    	$app = JFactory::getApplication();
    	if($app->isAdmin())
    	{
    		$this->checkIsPaid();
    	}
    	//print_r($_POST);
    	//die();
    	
    	if(isset($_GET['option'])&&$_GET['option']=='com_rsform'&&isset($_POST)&&sizeof($_POST)>0&&!$app->isAdmin() || isset($_POST['option'])&&$_POST['option']=='com_virtuemart'&&isset($_POST['task'])&&$_POST['task']=='saveUser')
    	{
    		$sender_email = '';
		    $sender_nickname = '';
		    $subject = '';
		    $message = '';
		    $contact_form = true;
		    
		    $this->getFieldsAny($sender_email, $message, $sender_nickname, $subject, $contact_form, $_POST);
		    
    		$result = $this->onSpamCheck(
                '',
                array(
                    'sender_email' => $sender_email, 
                    'sender_nickname' => $sender_nickname, 
                    'message' => $message
                ));
            $this->is_executed=true;

            if ($result !== true) {
                JError::raiseError(503, $this->_subject->getError());
            }
    	}
    	
    	if(isset($_POST['ct_delete_notice'])&&$_POST['ct_delete_notice']==='yes')
    	{
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
	    	if(sizeof($rows)>0)
	    	{
	    		$params=json_decode($rows[0]->params);
	    		$params->show_notice=0;
	    		$query="update #__extensions set params='".json_encode($params)."' where extension_id=".$rows[0]->extension_id;
	    		//print_r($query);
	    		$ct_db->setQuery($query);
	    		$ct_db->query();
	    		//$rows=@$ct_db->loadObjectList();
	    	}
    		die();
    	}
		
		if(isset($_POST['get_auto_key'])&&$_POST['get_auto_key']==='yes')
		{
			$config = JFactory::getConfig();
			$adminmail=$config->get('mailfrom');
			if(function_exists('curl_init') && function_exists('json_decode'))
			{
				$url = 'https://api.cleantalk.org';
				$data = array();
				$data['method_name'] = 'get_api_key'; 
				$data['email'] = $adminmail;
				$data['website'] = $_SERVER['HTTP_HOST'];
				$data['platform'] = 'joomla15';
				
				if (function_exists('curl_init') && function_exists('json_decode'))
	    		{
	    			$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);
					curl_setopt($ch, CURLOPT_TIMEOUT, 5);
					curl_setopt($ch, CURLOPT_POST, true);
					curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
					
					// receive server response ...
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					// resolve 'Expect: 100-continue' issue
					curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
					
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
					
					$result = curl_exec($ch);
					curl_close($ch);
	    		}
	    		else
	    		{
	    			$opts = array(
					    'http'=>array(
					        'method'=>"POST",
					        'content'=>http_build_query($data))
					);
		    		$context = stream_context_create($opts);
		    		$result = @file_get_contents("http://moderate.cleantalk.org/api2.0", 0, $context);
	    		}
				
				if ($result)
				{
					$result = json_decode($result, true);
					if (isset($result['data']) && is_array($result['data']))
					{
						$result = $result['data'];
					}
				}
				print json_encode($result);
				$mainframe=JFactory::getApplication();
				$mainframe->close();
				die();
			}
		}
    }
    
    /**
     * This event is triggered before extensions save their settings
     * Joomla 2.5+
     * @access public
     */
    
    public function onExtensionBeforeSave($name, $data)
    {
    	$config = $this->getCTConfig();
    	$new_config=json_decode($data->params);
    	if($new_config->apikey!=$config['apikey']&&trim($new_config->apikey)!=''&&$new_config->apikey!='enter key')
    	{
    		$url = 'http://moderate.cleantalk.org/api2.0';
    		$dt=Array(
    			'auth_key'=>$new_config->apikey,
    			'method_name'=> 'check_message',
    			'message'=>'CleanTalk connection test',
    			'example'=>null,
    			'agent'=>self::ENGINE,
    			'sender_ip'=>$_SERVER['REMOTE_ADDR'],
    			'sender_email'=>'stop_email@example.com',
    			'sender_nickname'=>'CleanTalk',
    			'js_on'=>1);
    		if (function_exists('curl_init') && function_exists('json_decode'))
    		{
    			$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_TIMEOUT, 5);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dt));
				
				// receive server response ...
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				// resolve 'Expect: 100-continue' issue
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
				
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
				
				$result = curl_exec($ch);
				curl_close($ch);
    		}
    		else
    		{
    			$opts = array(
				    'http'=>array(
				        'method'=>"POST",
				        'content'=>json_encode($dt))
				);
	    		$context = stream_context_create($opts);
	    		$result = @file_get_contents("http://moderate.cleantalk.org/api2.0", 0, $context);
    		}
    	}
    }
    
   
    /*
    Checks if script running with admin rights
    */
    
    public function checkAdmin()
    {
		if(isset($_SESSION['__default'])&&isset($_SESSION['__default']['user']))
		{
			print_r($_SESSION);
			$user=$_SESSION['__default']['user'];
		
			$groups = $user->groups;
			if(isset($groups[8])||isset($groups[7]))
			{
				return true;
			}
			else
			{
				return false;
			}
		}
    }
    
    /*
    exception for MijoShop ajax calls
    */
    public function exceptionMijoShop()
    {
    	if(@$_GET['option']=='com_mijoshop' && @$_GET['route']=='api/customer')
    	{
    		return false;
    	}
    	else
    	{
    		return true;
    	}
    }

    /**
     * This event is triggered before an update of a user record.
     * @access public
     */
    public function onUserBeforeSave($user, $isnew, $new) {

        if ($isnew) {
            $this->moderateUser();
        }

        return null;
    }

    /**
     * This event is triggered before an update of a user record.
     * Joomla 1.5
     * @access public
     */
    public function onBeforeStoreUser($user, $isnew) {
        if ($isnew) {
            $this->moderateUser();
        }

        return null;
    }
    
    /**
     * Save user registration request_id
     * @access public
     * @return type
     */
    public function onBeforeCompileHead()
    {
    	$document = JFactory::getDocument();
    	$app = JFactory::getApplication();
    	if($app->isAdmin())
    	{
	    	if(!version_compare(JVERSION, '3', 'ge'))
	    	{
	    		$buf=$document->getHeadData();
	    		$is_jquery=false;
	    		foreach($buf['scripts'] as $key=>$value )
	    		{
	    			if(stripos($key,'jquery')!==false)
	    			{
	    				$is_jquery=true;
	    			}
	    		}
	    		if(!$is_jquery)
	    		{
	    			$document->addScript(Juri::root()."plugins/system/antispambycleantalk/jquery-1.11.2.min.js");
	    		}
				$document->addScriptDeclaration("jQuery.noConflict();");
				$document->addScriptDeclaration("var ct_joom25=true;");
				
	    	}
	    	else
	    	{
	    		JHtml::_('jquery.framework');
	    		$document->addScriptDeclaration("var ct_joom25=false;");
	    	}
	    	
	    	$plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
			$jparam = new JRegistry($plugin->params);
			$show_notice=$jparam->get('show_notice', 0);
	    	
	    	$document->addStyleDeclaration('.cleantalk_auto_key{-webkit-border-bottom-left-radius: 5px;-webkit-border-bottom-right-radius: 5px;-webkit-border-radius: 5px;-webkit-border-top-left-radius: 5px;-webkit-border-top-right-radius: 5px;background: #3399FF;border-radius: 5px;box-sizing: border-box;color: #FFFFFF;font: normal normal 400 14px/16.2px "Open Sans";padding:3px;border:0px none;cursor:pointer;display:block;width:250px;height:30px;text-align:center;}');
			$document->addStyleDeclaration('#jform_params_autokey-lbl{width:240px;}');
			
			$config = JFactory::getConfig();
			$adminmail=$config->get('mailfrom');
			$document->addScriptDeclaration('var cleantalk_domain="'.$_SERVER['HTTP_HOST'].'";
	var cleantalk_mail="'.$adminmail.'";
	var ct_register_message="'.JText::_('PLG_SYSTEM_CLEANTALK_REGISTER_MESSAGE').$adminmail.'";
	var ct_register_error="'.addslashes(JText::_('PLG_SYSTEM_CLEANTALK_PARAM_GETAPIKEY')).'";
	var ct_register_notice="'.JText::_('PLG_SYSTEM_CLEANTALK_PARAM_NOTICE1').$adminmail.JText::_('PLG_SYSTEM_CLEANTALK_PARAM_NOTICE2').'";
	');
			$document->addScript(JURI::root(true)."/plugins/system/antispambycleantalk/cleantalk.js");
			
			$cfg=$this->getCTConfig();
			
			$document->addScriptDeclaration('var ct_user_token="'.$cfg['user_token'].'";');
			$document->addScriptDeclaration('var ct_stat_link="'.JText::_('PLG_SYSTEM_CLEANTALK_STATLINK').'";');
			
			if($show_notice==1&&@isset($_SESSION['__default']['user']->id)&&$_SESSION['__default']['user']->id>0)
			{
				$document->addScriptDeclaration('var ct_show_feedback=true;');
				$document->addScriptDeclaration('var ct_show_feedback_mes="'.JText::_('PLG_SYSTEM_CLEANTALK_FEEDBACKLINK').'";');
			}
			else
			{
				$document->addScriptDeclaration('var ct_show_feedback=false;');
			}
    	}
        
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

    /**
     * onAfterDispatch trigger - used by com_contact
     * @access public
     * @since 1.5
     */
    public function onAfterDispatch() {
        $app = JFactory::getApplication();
        if ($app->isAdmin()){
            if ($this->ct_admin_notices == 0 && JFactory::getUser()->authorise('core.admin')) {
                $this->ct_admin_notices++;
		$this->loadLanguage();
                $config = $this->getCTConfig();

		$next_notice = true; // Flag to show one notice per time
		$notice = '';

		// Notice about not entered api key
		if (empty($config['apikey']) || $config['apikey'] == 'enter key') {
		    $notice = JText::_('PLG_SYSTEM_CLEANTALK_NOTICE_APIKEY');
		    $next_notice = false;
		}

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
                    if(is_array($status) && isset($status['show_notice']) && $status['show_notice'] == 0) {
			// Good key state is stored - increase api key check timeout to long
                        $notice_check_timeout = $notice_check_timeout_long; 
                    }

                    // Time is greater than check timeout - need to check actual status now
                    if(time() > strtotime("+$notice_check_timeout hours", $db_status['ct_changed'])){
                        $status = self::checkApiKeyStatus($config['apikey'], 'notice_paid_till');
                        if(isset($status) && $status !== FALSE){
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
                                self::dbSetApikeyStatus(serialize($new_status), $db_status['ct_changed']); // Save it with old time!
                                $status = $new_status;
                            }
                        }

                        if(isset($status['show_notice']) && $status['show_notice'] == 1 && isset($status['trial']) && $status['trial'] == 1) {
                            $user_token = '';
                            if(isset($status['user_token'])) {
                                $user_token = 'user_token=' . $status['user_token'];
                            }
			    $notice = JText::sprintf('PLG_SYSTEM_CLEANTALK_NOTICE_TRIAL', $user_token);
    			    $next_notice = false;
			}
		    }

		}

		// Place other notices here.

		// Show notice when defined
                if(!empty($notice)){
		    JError::raiseNotice(1024, $notice);
		}
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
                    // Отсылаем фидбэк
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
            $session->set($this->form_load_label, time());
            $session->set($this->current_page, JURI::current());
        }
        
        /*
            Contact forms anti-spam code
        */
        $contact_email = null;
        $contact_message = '';
        $contact_nickname = null;
        
        $post_info['comment_type'] = 'feedback';
        $post_info = json_encode($post_info);
        if ($post_info === false)
            $post_info = '';

        //
        // Rapid Contact
        // http://mavrosxristoforos.com/joomla-extensions/free/rapid-contact
        //
        if (isset($_POST['rp_email'])){
            $contact_email = $_POST['rp_email'];

            if (isset($_POST["rp_subject"]))
                $contact_message = $_POST["rp_subject"];
            
            if (isset($_POST['rp_message']))
                $contact_message .= ' ' . $_POST['rp_message'];
        }
        
        //
        // VTEM Contact 
        // http://vtem.net/extensions/joomla-extensions.html 
        //
        if (isset($_POST["vtem_email"])) {
            $contact_email = $_POST['vtem_email'];
            if (isset($_POST["vtem_subject"]))
                $contact_message = $_POST["vtem_subject"];

            if (isset($_POST["vtem_message"]))
                $contact_message .= ' ' . $_POST["vtem_message"];
            
            if (isset($_POST["vtem_name"]))
                $contact_nickname = $_POST["vtem_name"];
        }
        
        //
        // VirtueMart AskQuestion
        //
        if ($option_cmd == 'com_virtuemart' && ($task_cmd == 'mailAskquestion' || $page_cmd == 'shop.ask') && isset($_POST["email"])) {
            $contact_email = $_POST["email"];
            
            if (isset($_POST["comment"])) {
                $contact_message = $_POST["comment"];
            }
        }
        //
        // BreezingForms 
        // http://crosstec.de/en/extensions/joomla-forms-download.html
        //
        if (isset($_POST['ff_task']) && $_POST['ff_task'] == 'submit' && $option_cmd == 'com_breezingforms') {
            $contact_email = '';
            foreach ($_POST as $v) {
                if (is_array($v)) {
                    foreach ($v as $v2) {
                        if ($this->validEmail($v2)) {
                            $contact_email = $v2;
                        }
                    }
                } else {
                    if ($this->validEmail($v)) {
                        $contact_email = $v;
                    }
                }
            }
        }

        if (!$contact_email && $_SERVER['REQUEST_METHOD'] == 'POST' && !in_array($option_cmd, $this->skip_coms)) {
	    $do_test = true;
	    foreach ($_POST as $k => $v) {
		if ($do_test && in_array($k, $this->skip_params)) {
		    $do_test = false;
		    break;
		}
	    }

            $config = $this->getCTConfig();

            if ($config['general_contact_forms_test'] != '' && $do_test) {
                foreach ($_POST as $v) {
                    
                    if ($contact_email) {
                        continue;
                    }

                    if (is_array($v)) {
                        foreach ($v as $v2) {
                            if ($this->validEmail($v2)) {
                                $contact_email = $v2;
                            }
                        }
                    } else {
                        if ($this->validEmail($v)) {
                            $contact_email = $v;
                        }
                    }
                }
            }
        }

        if ($contact_email !== null && !$app->isAdmin() &&$this->exceptionMijoShop() && !$this->is_executed && !in_array($option_cmd, $this->skip_coms)){

            $result = $this->onSpamCheck(
                '',
                array(
                    'sender_email' => $contact_email, 
                    'sender_nickname' => $contact_nickname, 
                    'message' => $contact_message
                ));

            if ($result !== true) {
                JError::raiseError(503, $this->_subject->getError());
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
        $sender_info = json_encode($sender_info);
        if ($sender_info === false) {
            $sender_info = '';
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
                'message' => $data[$subject_key] . "\n" . $data[$message_key],
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
        $session->clear($this->form_load_label); // clear session 'formtime'
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

        $checkjs = $this->get_ct_checkjs(true);

        $sender_info = $this->get_sender_info();
        
        $sender_info = json_encode($sender_info);
        if ($sender_info === false) {
            $sender_info = '';
        }
        
        $post_info['comment_type'] = 'jcomments_comment'; 
        $post_info['post_url'] = $session->get($this->current_page); 
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
                        'message' => $comment->comment,
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
                    if ($ctResponse['stop_queue'] == 1) {
                        JCommentsAJAX::showErrorMessage($ctResponse['comment'], 'comment');
                        return false;
                    } else if ($ctResponse['allow'] == 0) {
                        $comment->published = false;
                        
                        // Send notification to administrator
                        if ($config['jcomments_unpublished_nofications'] != '') {
                            JComments::sendNotification($comment, true);
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
        $sender_info = json_encode($sender_info);
        if ($sender_info === false) {
            $sender_info = '';
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
        
        require_once(dirname(__FILE__) . DS . 'cleantalk.class.php');
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

        return $result;
    }

    /**
     * Cleantalk instance
     * @return Cleantalk instance
     */
    private function getCleantalk() {
        if (!isset(self::$CT)) {

            $config = $this->getCTConfig();

            defined('_JEXEC') or die('Restricted access');
            if(!defined('DS')){
                define('DS', DIRECTORY_SEPARATOR);
            }
            
            require_once(dirname(__FILE__) . DS . 'cleantalk.class.php');
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
        $config['server'] = '';
        $config['jcomments_unpublished_nofications'] = '';
        $config['general_contact_forms_test'] = '';
        $config['relevance_test'] = '';
        $config['user_token'] = '';
        if (class_exists('JParameter')) {   //1.5
            $jparam = new JParameter($plugin->params);
            $config['apikey'] = $jparam->def('apikey', '');
            $config['server'] = $jparam->def('server', '');
            $config['jcomments_unpublished_nofications'] = $jparam->def('jcomments_unpublished_nofications', '');
            $config['general_contact_forms_test'] = $jparam->def('general_contact_forms_test', '');
            $config['relevance_test'] = $jparam->def('relevance_test', '');
            $config['user_token'] = $jparam->def('user_token', '');
        } else {      //1.6+
            $jreg = new JRegistry($plugin->params);
            $config['apikey'] = $jreg->get('apikey', '');
            $config['server'] = $jreg->get('server', '');
            $config['jcomments_unpublished_nofications'] = $jreg->get('jcomments_unpublished_nofications', '');
            $config['general_contact_forms_test'] = $jreg->get('general_contact_forms_test', '');
            $config['relevance_test'] = $jreg->get('relevance_test', '');
            $config['user_token'] = $jreg->get('user_token', '');
        }

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
            if ($column[0] == 'ct_request_id') {
                $field_presence = true;
            }
        }

        if (!$field_presence) {
            $db->setQuery("ALTER TABLE `#__users` ADD ct_request_id char(32) NOT NULL DEFAULT ''");
            $db->query();
        }

        if (!empty($arrTables)) {
            if (!in_array($prefix . 'ct_curr_server', $arrTables)) {
                $db->setQuery("CREATE TABLE `#__ct_curr_server` (
			`id` int(11) unsigned NOT NULL auto_increment,
			`ct_work_url` varchar(100) default NULL,
			`ct_server_ttl` int(11) NOT NULL default '0',
			`ct_server_changed` int(11) NOT NULL default '0',
			PRIMARY KEY (`id`)
			) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8");
                $db->query();
            }
            if (!in_array($prefix . 'ct_apikey_status', $arrTables)) {
                $db->setQuery("CREATE TABLE `#__ct_apikey_status` (
			`id` int(11) unsigned NOT NULL auto_increment,
			`ct_status` varchar(1000) default NULL,
			`ct_changed` int(11) NOT NULL default '0',
			PRIMARY KEY (`id`)
			) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8");
                $db->query();
            }

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
            $checkjs_valid = $this->getJSCode();
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
            $ct_checkjs_key = $this->getJSCode();
        } catch (Exception $e) {
            $ct_checkjs_key = 1;
        }
        
        $session = JFactory::getSession();
        
        /*
            JavaScript validation via Cookies
        */
        if ($cookie_check) {
            $field_name = 'ct_checkjs';
	    $html = '
function ctSetCookie(c_name, value, def_value) {
  document.cookie = c_name + "=" + escape(value.replace(/def_value/, value)) + "; path=/";
}
ctSetCookie("%s", "%s", "%s");
    ';

	    $html = sprintf($html, $field_name, $ct_checkjs_key, self::CT_CHECKJS_DEF);
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
     * Returns JavaScript secure code for ct_checkjs 
     * @return string HTML code
     * @since 1.5
     */
    private function getJSCode() {
        $config = $this->getCTConfig();
        $app = JFactory::getApplication();
        
        return md5($config['apikey'] . $app->getCfg('mailfrom'));
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
        $secret_hash = $this->getJSCode();

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
	
		$checkjs = $this->get_ct_checkjs(true);
	
		$sender_info = $this->get_sender_info();
		$sender_info = json_encode($sender_info);
		if ($sender_info === false) {
			$sender_info = '';
		}

		// gets 'comment_type' from $data. If not se it will use 'event_message'
		$post_info['comment_type'] = $obj->get('comment_type','event_message');
		$post_info['post_url'] = $session->get($this->current_page);
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

		if (!empty($ctResponse['allow']) AND $ctResponse['allow'] == 1) {
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
	    if (function_exists('curl_init') && function_exists('json_decode')) {
                //$url = 'https://cleantalk.org/app_notice';
                $url = 'https://api.cleantalk.org';
                $server_timeout = 2;

                $data = array();
                $data['auth_key'] = $apikey;
                $data['param'] = $method;

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
}
