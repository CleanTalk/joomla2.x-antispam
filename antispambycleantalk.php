<?php

/**
 * CleanTalk joomla plugin
 *
 * @version 2.2
 * @package Cleantalk
 * @subpackage Joomla
 * @author CleanTalk (welcome@cleantalk.ru) 
 * @copyright (C) 2013 Сleantalk team (http://cleantalk.org)
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
    const ENGINE = 'joomla-22';
    
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

    /*
    * Flag marked JComments form initilization. 

    */
    private $JCReady = false;

    /*
    * Page load label
    *
    */
    private $form_load_label = 'formtime';
    
    /*
    * Page load label
    *
    */
    private $current_page = null;
    
    /**
    * Form submited without page load
    */
    private $ct_direct_post = 0;
    
    /**
    * This event is triggered before an update of a user record.
    */
    function onUserBeforeSave($user, $isnew, $new) {

        if ($isnew) {
            $this->moderateUser();
        }

        return null;
    }
    /**
    * This event is triggered before an update of a user record.
    * Joomla 1.5
    */
    function onBeforeStoreUser($user, $isnew) {
        if ($isnew) {
            $this->moderateUser();
        }

        return null;
    }
    /**
     * Include in head adn fill form
     * @param type $form_id
     * @param type $data
     * @return string
     */
    function fillRegisterFormScriptHTML($form_id, $data = null, $onLoad = true) {
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
     * Save user registration request_id
     * @return type
     */
    function onBeforeCompileHead() {
        $app = JFactory::getApplication();
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
     * Moderate new user
     */
    function moderateUser() {
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

    /**
     * Constructor
     * @param $subject
     * @param $config
     * @return void
     */
    function plgSystemAntispambycleantalk (&$subject, $config) {
        parent::__construct($subject, $config);
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
    function onValidateContact(&$contact, &$data) {
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

    function sendAdminEmail($subject, $message, $is_html = false) {
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
     * onAfterDispatch trigger - used by com_contact
     * @access public
     * @since 1.5
     */
    function onAfterDispatch() {
        $app = JFactory::getApplication();
        if ($app->isAdmin())
            return;
        
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
    function onAfterRoute() {
        $option_cmd = JRequest::getCmd('option');
        $view_cmd = JRequest::getCmd('view');
        $task_cmd = JRequest::getCmd('task');
        $page_cmd = JRequest::getCmd('page');
        
        $ver = new JVersion();
        $app = JFactory::getApplication();
        if ($app->isAdmin()) {
            if ($option_cmd == 'com_users') {
                $task_cmd_remove = 'users.delete'; //2.5
                if (strcmp($ver->RELEASE, '1.5') <= 0) {
                    $task_cmd_remove = 'remove';
                }
                if ($task_cmd == $task_cmd_remove) {
                    // Отсылаем фидбэк
                    $a = 1;
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

        if ($contact_email !== null){
            $result = $this->onSpamCheck($contact_email, $contact_nickname, $contact_message);

            if ($result !== true) {
                JError::raiseError(503, $result);
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
    function onJCommentsFormAfterDisplay() {
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
    function onJCommentsCommentBeforeAdd(&$comment) {
        
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
        error_log(print_r($config['relevance_test'], true)); 
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
                        $comment->comment = self::$CT->addCleantalkComment($comment->comment, $ctResponse['comment']);
                        
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

    /**
     * onJCommentsCommentBeforePublish trigger
     * @access public
     * @param JCommentsDB $comment
     * @return bolean true
     * @since 1.5
     */
    function onJCommentsCommentBeforePublish(&$comment) {
        self::moderateMessage($comment->comment, 1);
        return true;
    }

    /**
     * onJCommentsCommentAfterDelete trigger
     * @access public
     * @param JCommentsDB $comment
     * @return bolean true
     * @since 1.5
     */
    function onJCommentsCommentAfterDelete(&$comment) {
        self::moderateMessage($comment->comment, 0);
        return true;
    }

    ////////////////////////////
    // Common basic sutff

    /**
     * Function to send the results of moderation
     * @param $message
     * @param $allow
     * @return void
     */
    function moderateMessage(&$message, $allow) {
        self::getCleantalk();
        $hash = self::$CT->getCleantalkCommentHash($message);
        $resultMessage = self::$CT->delCleantalkComment($message);

        if ($hash != NULL) {
            $ctFbParams = array(
                'moderate' => array(
                    array('msg_hash' => $hash, 'is_allow' => $allow),
                ),
            );

            self::ctSendRequest(
                    'send_feedback', $ctFbParams
            );
        }

        $message = $resultMessage;
    }

    /**
     * Interface to XML RPC server
     * $mehod - method name
     * $params - array of XML params
     * return XML RPS server response
     */
    function ctSendRequest($method, $params) {
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
    function getCleantalk() {
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
    function getCTConfig() {
        $plugin = JPluginHelper::getPlugin('system', 'antispambycleantalk');
            
        $config['apikey'] = ''; 
        $config['server'] = '';
        $config['jcomments_unpublished_nofications'] = '';
        $config['relevance_test'] = '';
        if (class_exists('JParameter')) {   //1.5
            $jparam = new JParameter($plugin->params);
            $config['apikey'] = $jparam->def('apikey', '');
            $config['server'] = $jparam->def('server', '');
            $config['jcomments_unpublished_nofications'] = $jparam->def('jcomments_unpublished_nofications', '');
            $config['relevance_test'] = $jparam->def('relevance_test', '');
        } else {      //1.6+
            $jreg = new JRegistry($plugin->params);
            $config['apikey'] = $jreg->get('apikey', '');
            $config['server'] = $jreg->get('server', '');
            $config['jcomments_unpublished_nofications'] = $jreg->get('jcomments_unpublished_nofications', '');
            $config['relevance_test'] = $jreg->get('relevance_test', '');
        }

        return $config;
    }


    /**
     * Cleantalk tables creator
     * @return bool
     */
    function initTables() {
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
        }
        return self::$tables_ready;
    }

    /**
     * Current server getter
     * @return array
     */
    function dbGetServer() {
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
    function dbSetServer($ct_work_url, $ct_server_ttl, $ct_server_changed) {
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
    * Get value of $ct_checkjs
    * JavaScript avaibility test.
    * @return null|0|1    
    */
    function get_ct_checkjs($cookie_check = true){

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
     * @access public
     * @return null 
     * @since 1.5
     */
    function getJSTest($needle = null, $after = false, $cookie_check = false) {
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
function ctSetCookie(c_name, value) {
    document.cookie = c_name + "=" + escape(value) + "; path=/";
}
ctSetCookie("%s", "%s");
    ';
		    $html = sprintf($html, $field_name, $ct_checkjs_key);
            
            return $html;
        }

        $field_id = 'ct_checkjs_' . md5(rand(0, 1000));

        $str = '<input type="hidden" id="' . $field_id . '" name="ct_checkjs" value="' . self::CT_CHECKJS_DEF . '" />'. "\n";
        $str .= '<script type="text/javascript">'. "\n";
        $str .= '// <![CDATA['. "\n";
        $str .= 'document.getElementById("'. $field_id .'").value = document.getElementById("'. $field_id .'").value.replace(/^' . self::CT_CHECKJS_DEF . '$/, "' . $ct_checkjs_key . '");'. "\n";
        $str .= 'console.log(document.getElementById("'. $field_id .'").value);'. "\n";
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
     * @access public
     * @return string HTML code
     * @since 1.5
     */
    function getJSCode() {
        $config = $this->getCTConfig();

        return md5($config['apikey'] . $_SERVER['REMOTE_ADDR']);
    }
    
    /**
     * Valids email 
     * @access public
     * @return bool 
     * @since 1.5
     */
    function validEmail($string) {
        return preg_match("/^\S+@\S+$/i", $string); 
    }
    
    /**
     * Validate form submit time 
     *
     */
    function submit_time_test() {
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
    function get_sender_info() {
        $session = JFactory::getSession();
        
        return $sender_info = array(
            'REFFERRER' => @$_SERVER['HTTP_REFERER'],
            'USER_AGENT' => @$_SERVER['HTTP_USER_AGENT'],
            'direct_post' => $this->ct_direct_post,
            'cookies_enabled' => $this->ct_cookies_test(true), 
        );
    }

    /**
     * Cookies test for sender 
     * @return null|0|1;
     */
    function ct_cookies_test ($test = false) {
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
	public function onSpamCheck($context='', $data){
		// Converts $data Array into an Object
		$obj = new JObject($data);
		// sets 'sender_email' ONLY if not already set. Also checks to see if 'email' was not provided instead
		$obj->def('sender_email',$obj->get('email',null));
		// sets 'sender_nickname' ONLY if not already set. Also checks to see if 'name' was not provided instead
		$obj->def('sender_nickname',$obj->get('name',null));
		// sets 'message' ONLY if not already set. Also checks to see if 'comment' was not provided instead
		$obj->def('message',$obj->get('comment',null));
	
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


}
