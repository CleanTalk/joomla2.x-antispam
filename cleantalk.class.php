<?php
defined('_JEXEC') or die('Restricted access');
jimport('joomla.plugin.plugin');
jimport('joomla.application.application');
jimport('joomla.application.component.helper');
if(!defined('DS')){
    define('DS', DIRECTORY_SEPARATOR);
}
require_once(dirname(__FILE__) . DS . 'classes'. DS .'Cleantalk.php');
require_once(dirname(__FILE__) . DS . 'classes'. DS .'CleantalkRequest.php');
require_once(dirname(__FILE__) . DS . 'classes'. DS .'CleantalkResponse.php');
?>