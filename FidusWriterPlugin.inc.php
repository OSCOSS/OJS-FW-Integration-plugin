<?php

/**
 * Copyright 2016-17, Afshin Sadeghi (sadeghi@cs.uni-bonn.de) of the OSCOSS
 * Project.
 * License: MIT. See LICENSE.md for details.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class FidusWriterPlugin extends GenericPlugin {

    /**
     * @param $category
     * @param $path
     * @return bool
     */

    function register($category, $path) {
        if (parent::register($category, $path)) {
			/* Note: it looks counterintuitive that only the first listener checks
			   whether the plugin is enabled, but the way OJS is set up, if one
			   moves the other listeners inside the check, they stop working.
			*/
            if ($this->getEnabled()) {
                HookRegistry::register('PluginRegistry::loadCategory', array($this, 'callbackLoadCategory'));
            }
			HookRegistry::register('reviewassignmentdao::_insertobject', array($this, 'callbackAddReviewer'));
			HookRegistry::register('reviewassignmentdao::_deletebyid', array($this, 'callbackRemoveReviewer'));
			HookRegistry::register('reviewrounddao::_insertobject', array($this, 'newRevisionWebHook'));
			HookRegistry::register('TemplateManager::fetch', array($this, 'templateFetchCallback'));
			// Add fields fidusRevisionId and fidusUri to submissions
			HookRegistry::register('articledao::getAdditionalFieldNames', array($this, 'callbackAdditionalFieldNames'));
            return true;
        }
        return false;
    }

    // BEGIN STANDARD PLUGIN FUNCTIONS

    /**
     * Get the name of the settings file to be installed on new context
     * creation.
     * @return string
     */
    function getContextSpecificPluginSettingsFile() {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * Override the builtin to get the correct template path.
     * @return string
     */
    function getTemplatePath($inCore = false) {
 		return parent::getTemplatePath($inCore) . 'templates/';
 	}

    /**
     * Get the display name for this plugin.
     *
     * @return string
     */
    function getDisplayName() {
        return __('plugins.generic.fidusWriter.displayName');

    }

    /**
     * Get a description of this plugin.
     *
     * @return string
     */
    function getDescription() {
        return __('plugins.generic.fidusWriter.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    function getActions($request, $verb) {
        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        return array_merge(
            $this->getEnabled()?array(
                new LinkAction(
                    'settings',
                    new AjaxModal($router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ):array(),
            parent::getActions($request, $verb)
        );
    }


	/**
	 * @copydoc Plugin::manage()
	 */
    function manage($args, $request) {
        $this->import('FidusWriterSettingsForm');
 		switch ($request->getUserVar('verb')) {
 			case 'settings':
				$settingsForm = new FidusWriterSettingsForm($this);
				$settingsForm->initData();
				return new JSONMessage(true, $settingsForm->fetch($request));
                break;
			case 'save':
				$settingsForm = new FidusWriterSettingsForm($this);
				$settingsForm->readInputData();
				if ($settingsForm->validate()) {
					$settingsForm->execute();
					$notificationManager = new NotificationManager();
					$notificationManager->createTrivialNotification(
						$request->getUser()->getId(),
						NOTIFICATION_TYPE_SUCCESS,
						array('contents' => __('plugins.generic.fidusWriter.settings.saved'))
					);
					return new JSONMessage(true);
				}
				return new JSONMessage(true, $settingsForm->fetch($request));
                break;
 		}
 		return parent::manage($args, $request);
 	}

    /**
     * @see Plugin::isSitePlugin()
     */
    function isSitePlugin() {
        return true;
    }

    // END STANDARD PLUGIN FUNCTIONS

	function getGatewayPluginUrl() {
        $request =& Registry::get('request');
        return $request->getBaseUrl() . '/index.php/index/gateway/plugin/FidusWriterGatewayPlugin';
    }

    function getApiKey() {
        return $this->getSetting(CONTEXT_ID_NONE, 'apiKey');
    }

	/**
	* Retrieve a submission setting from the DB. We use this to get fidusUrl and
	* fidusRevisionId.
	* @param $hookName
	* @param $args
	* @return bool
	*/
	function getSubmissionSetting($submissionId, $settingName) {
		$submissionDao = Application::getSubmissionDAO();
		$submission = $submissionDao->getById($submissionId);
		return $submission->getData($settingName);
	}


	/**
	 * Add fieldnames to link submissions to revisions in Fidus Writer
	 * instances.
	 * @see DAO::getAdditionalFieldNames()
	 */
	function callbackAdditionalFieldNames($hookName, $args) {
		$returner =& $args[1];
		$returner[] = 'fidusUri';
		$returner[] = 'fidusRevisionId';
	}

	/**
	 * We override the template for the submission file grid in case of a Fidus
	 * based submission. If the submission is connected to a Fidus Writer instance,
	 * we instead show a login link to get to the fidus writer instance (via the
	 * Fidus Writer Gateway plugin).
	 * @param $hookName
     * @param $args
     * @return bool
     */
	public function templateFetchCallback($hookName, $args) {
		$templateManager = $args[0];
		$templateName = $args[1];
		if ($templateName == 'controllers/grid/grid.tpl') {
			$grid = $templateManager->get_template_vars('grid');
			$title = $grid->getTitle();

			if ($title==='submission.submit.submissionFiles' || $title==='reviewer.submission.reviewFiles') {
				// Not sure if there is another way to find this information,
				// but the submissionId is part of the URL of this page.
				$submissionId =  intval($_GET['submissionId']);
				$fidusRevisionId = $this->getSubmissionSetting($submissionId, 'fidusRevisionId');
				if ($fidusRevisionId != false) {
					// This submission is linked to a Fidus Writer instance, so present
					// link rather the file overview.
					// If the submission file section is requested, we override the
					// entire grid with a link to the file in Fidus Writer. This way
					// there are no surprises of users accidentally trying to add
					// more files or similar.

					$result =& $args[4];
					$result = '
					<div class="pkp_controllers_grid">
						<div class="header">
						<h4><a href="' . $this->getGatewayPluginUrl() . '/documentReview?submissionId=' . $submissionId . '">
							' . __('plugins.generic.fidusWriter.linkText') . '
						</a></h4>
						</div>
					</div>';
					return true;
				}

			}

		}
	}

    /**
	 * Sends information about a newly registered reviewer for a specific submission
	 * to Fidus Writer, if the submission is of a document in Fidus Writer.
     * @param $hookName
     * @param $args
     * @return bool
     */
    function callbackAddReviewer($hookName, $args) {
        $row =& $args[1];
        $submissionId = $row[0];
		$fidusRevisionId = $this->getSubmissionSetting($submissionId, 'fidusRevisionId');
		if ($fidusRevisionId === false) {
			// The article was not connected with Fidus Writer, so we send no
			// notification.
			return false;
		}
		$reviewerId = $row[1];
        $reviewer = $this->getUser($reviewerId);
        $dataArray = [
			'email' => $reviewer->getEmail(),
			'username' => $reviewer->getUserName(),
			'user_id' => $reviewerId,
			'key' => $this->getApiKey()
		];
		$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');
        $url = $fidusUrl . '/ojs/add_reviewer/' . $fidusRevisionId . '/';

        // then send the email address of reviewer to AT.
        // Authoring tool must give review access to this article with the submission id
        $this->sendPostRequest($url, $dataArray);
        return false;
    }

    /**
	 * Sends information to Fidus Writer that a given reviewer has been removed
	 * from a submission so that Fidus Writer also removes the access the reviewer
	 * has had to the document in question.
     * @param $hookName
     * @param $args
     * @return bool
     */
    function callbackRemoveReviewer($hookName, $args) {
		$reviewId =& $args[1];
		$submissionId = $this->getSubmissionIdByReviewId($reviewId);
		$fidusRevisionId = $this->getSubmissionSetting($submissionId, 'fidusRevisionId');

		if ($fidusRevisionId === false) {
			// The article was not connected with Fidus Writer, so we send no
			// notification.
			return false;
		}

        $userId = $this->getUserIdByReviewId($reviewId);
        $dataArray = [
			'user_id' => $userId,
			'key' => $this->getApiKey()
		];
        // Then send the email address of reviewer to Fidus Writer.
		$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');
		$url = $fidusUrl. '/ojs/remove_reviewer/' . $fidusRevisionId . '/';
        $this->sendPostRequest($url, $dataArray);
        return false;
    }


    /**
     * Creates New In Editor Article Revision By OJS Editor User
     * @param $hookname
     * @param $args
     */
    function newRevisionWebHook($hookname, $args) {

        $revisionReqArr =& $args[1];
        $submissionId = $revisionReqArr[0];
        $round = $revisionReqArr[2];

        if($round == "1") return;
        // If $submissionId is 0, it is round 0 and no reviewer is assigned yet
        if (is_null($submissionId)) return;
        $authorEmail = $this->getAuthorEmailBySubmissionId($submissionId);
        // If $submissionId is 0, it is round 0 and no reviewer is assigned yet
        if (is_null($authorEmail)) return;
        $userName = $this->getAuthorUserNameBySubmissionId($submissionId);


        $dataArray = [
            'author_email' => $authorEmail,
            'author_user_name' => $userName,
            'key' => $this->getApiKey(), //shared key between OJS and Editor software
            'submission_id' => $submissionId,
            'round' => $round];  //editor user for logging in
        // Then send the email address of reviewer to authoring tool.
        // AT must give review access to this article with the submission id
		$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');
		if ($fidusUrl == false) {
			// Not connected to Fidus Writer
			return false;
		}
        $url = $fidusUrl . '/ojs/newsubmissionrevision/';
        $result = $this->sendPostRequest($url, $dataArray);
    }

    /**
     * @param $hookName string
     * @param $args array
     * @return bool
     **/
    function callbackLoadCategory($hookName, $args) {
        $category = $args[0];
        $plugins =& $args[1];
        switch ($category) {
            case 'gateways':
                $this->import('FidusWriterGatewayPlugin');
                $gatewayPlugin = new FidusWriterGatewayPlugin($this->getName());
                $plugins[$gatewayPlugin->getSeq()][$gatewayPlugin->getPluginPath()] = $gatewayPlugin;
                break;
        }
        return false;
    }

	function getUser($userId) {
		$userDao = DAORegistry::getDAO('UserDAO');
		return $userDao->getById($userId);
	}


    /**
     * @param $userId
     * @return string
     */
    function getUserEmail($userId) {
        /** @var UserDAO $userDao */
        $userDao = DAORegistry::getDAO('UserDAO');
        return $userDao->getUserEmail($userId);
    }

    /**
     * @param $userId
     * @return string
     */
    function getUserName($userId) {
        /** @var UserDAO $userDao */
        $userDao = DAORegistry::getDAO('UserDAO');
        /** @var ReviewAssignment $reviewAssignment */
        $user = $userDao->getById($userId);
        /** @var User $user */
        return $user->getUsername($userId);
    }


    /**
     * @param $reviewId
     * @return mixed
     */
    function getUserIdByReviewId($reviewId) {
        $userDao = DAORegistry::getDAO('UserDAO');
        /** @var ReviewAssignmentDAO $RADao */
        $RADao = DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewAssignmentArray = $RADao->getById($reviewId);
		// TODO: Find out if there are any problems here if this assignment
		// contains more than one reviewer.
        if (is_array($reviewAssignmentArray)) {
            $reviewAssignment = $reviewAssignmentArray[0];
        } else {
            $reviewAssignment = $reviewAssignmentArray;
        }
        /** @var ReviewAssignment $reviewAssignment */
        $userId = $reviewAssignment->getReviewerId();
        return $userId;
    }

    /**
     * @param $reviewId
     * @return int
     */
    function getSubmissionIdByReviewId($reviewId) {
        /** @var ReviewAssignmentDAO $RADao */
        $RADao = DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewAssignmentArray = $RADao->getById($reviewId);
        if (is_array($reviewAssignmentArray)) {
            $reviewAssignment = $reviewAssignmentArray[0];
        } else {
            $reviewAssignment = $reviewAssignmentArray;
        }
        /** @var ReviewAssignment $reviewAssignment */
        $submissionId = $reviewAssignment->getSubmissionId();
        return $submissionId;
    }

    /**
     * @param $requestType
     * @param $url
     * @param $dataArray
     * @return string
     */
    function sendRequest($requestType, $url, $dataArray) {
        $options = array(
            'http' => array(
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => $requestType,
                'content' => http_build_query($dataArray)
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === false) { /* Handle error */
            echo $result;
        }
        return $result;
    }


    /**
     * @param $url
     * @param $dataArray
     * @return string
     */
    function sendPostRequest($url, $dataArray) {
        $result = $this->sendRequest('POST', $url, $dataArray);
        return $result;
    }


    /**
     * @param $submissionId
     * @return mixed
     */
    function getAuthorEmailBySubmissionId($submissionId) {

        /** @var AuthorDao $authorDao */
        $authorDao = DAORegistry::getDAO('AuthorDAO');
        $authors = $authorDao->getBySubmissionId($submissionId);
        $email = "";
        foreach ($authors as $author){
            /** @var Author $author */
            $email = $author->getEmail(); //get the first author
            break;
        }
        //$email = $author->getEmail();
        return $email;
    }

    /**
     * @param $submissionId
     * @return string
     */
    function getAuthorUserNameBySubmissionId($submissionId) {
        /** @var AuthorDao $authorDao */
        $authorDao = DAORegistry::getDAO('AuthorDAO');
        $authors = $authorDao->getBySubmissionId($submissionId);
        $userName = "";
        foreach ($authors as $author){
            /** @var Author $author */
            $userName = $author->getFullName();
            break;
        }
        return $userName;
    }

}
