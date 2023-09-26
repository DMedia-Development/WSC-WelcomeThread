<?php

namespace wbb\system\event\listener;

use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\WCF;
use wcf\system\language\LanguageFactory;
use wcf\system\html\input\HtmlInputProcessor;
use wcf\data\user\User;
use wcf\util\StringUtil;
use wcf\util\MessageUtil;
use wcf\util\ArrayUtil;
use wbb\data\board\BoardCache;
use wbb\data\board\BoardEditor;
use wbb\data\thread\ThreadAction;

/**
 * Creates a welcome thread for new members of the website.
 *
 * @author Moritz Dahlke (DMedia)
 * @copyright 2021-2023 DMedia
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
class WelcomeThreadEventListener implements IParameterizedEventListener
{
    public function execute($eventObj, $className, $eventName, array &$parameters)
    {
        $this->$eventName($eventObj);
    }

    protected function saved()
    {
        // the new user should be logged in according to
        // https://github.com/WoltLab/WCF/blob/88b664266d747c0372b8c6cc329ac5846ef99baf/wcfsetup/install/files/lib/form/RegisterForm.class.php#L499
        $newUser = WCF::getSession()->getUser();
        if (!isset($newUser->userID)) {
            return;
        }

        // destination forum can not be empty
        $boardID = WELCOME_THREAD_DESTINATION;
        if (empty($boardID)) {
            return;
        }

        // thread title and content can not be empty
        if (empty(WELCOME_THREAD_TITLE) || empty(WELCOME_THREAD_CONTENT)) {
            return;
        }

        // check if thread author exists
        $threadAuthor = User::getUserByUsername(StringUtil::trim(WELCOME_THREAD_USER));
        if (!$threadAuthor->userID) {
            return;
        }

        // get thread title and content
        $threadTitle = WCF::getLanguage()->get(WELCOME_THREAD_TITLE);
        $threadContent = WCF::getLanguage()->get(WELCOME_THREAD_CONTENT);

        // insert HTML line breaks before all newlines
        $threadContent = \nl2br($threadContent, false);

        // replace variables in title and content
        $threadTitle = \str_replace('{username}', $newUser->username, $threadTitle);
        $threadContent = \str_replace('{username}', '@' . $newUser->username, $threadContent);

        // get thread tags
        $threadTags = [];
        if (MODULE_TAGGING && WBB_THREAD_ENABLE_TAGS && !empty(WELCOME_THREAD_TAGS)) {
            $threadTags = \array_unique(ArrayUtil::trim(\explode(',', WCF::getLanguage()->get(WELCOME_THREAD_TAGS))));
        }

        // create thread
        $htmlInputProcessor = new HtmlInputProcessor();
        $htmlInputProcessor->process($threadContent, 'com.woltlab.wbb.post');

        $threadAction = (new ThreadAction([], 'create', [
            'data' => [
                'boardID' => $boardID,
                'languageID' => $newUser->languageID ?: LanguageFactory::getInstance()->getDefaultLanguageID(),
                'topic' => \mb_substr(MessageUtil::stripCrap($threadTitle), 0, 255),
                'time' => TIME_NOW,
                'userID' => $threadAuthor->userID,
                'username' => $threadAuthor->username,
                'isClosed' => WELCOME_THREAD_CLOSE,
                'isDisabled' => WELCOME_THREAD_DISABLE
            ],
            'postData' => [
                'message' => $htmlInputProcessor->getHtml()
            ],
            'tags' => $threadTags,
            'subscribeThread' => false,
            'htmlInputProcessor' => $htmlInputProcessor
        ]))->executeAction();

        // update last post
        $boardEditor = new BoardEditor(BoardCache::getInstance()->getBoard($boardID));
        $boardEditor->updateLastPost();
    }
}
