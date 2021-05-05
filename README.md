Anti-spam plugin for Joomla 2.X.
============

Version 6.5

## Simple antispam test

Example how to use plugin to filter spam bots at any Joomla form.


            $result = plgSystemAntispambycleantalk::onSpamCheck(
                '',
                array(
                    'sender_email' => $contact_email, 
                    'sender_nickname' => $contact_nickname, 
                    'message' => $contact_message
                ));

            if ($result !== true) {
                JFactory::getApplication()->enqueueMessage($this->_subject->getError(),'error');
            }
