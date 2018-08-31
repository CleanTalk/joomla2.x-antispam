Anti-spam plugin for Joomla 2.5-3.X.
============
[![Build Status](https://travis-ci.org/CleanTalk/joomla25-3x-antispam.svg)](https://travis-ci.org/CleanTalk/joomla25-3x-antispam)

Version 5.7

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
                JError::raiseError(503, $this->_subject->getError());
            }
