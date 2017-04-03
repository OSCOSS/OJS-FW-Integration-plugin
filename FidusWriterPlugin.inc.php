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
			HookRegistry::register('reviewrounddao::_insertobject', array($this, 'callbackNewReviewRound'));
			HookRegistry::register('TemplateManager::fetch', array($this, 'templateFetchCallback'));
			// Add fields fidusId and fidusUri to submissions
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
	* fidusId.
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
		$returner[] = 'fidusUrl';
		$returner[] = 'fidusId';
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
			if (
                $title==='submission.submit.submissionFiles' ||
                $title==='reviewer.submission.reviewFiles' ||
                $title==='editor.submission.revisions'
            ) {
				// Not sure if there is another way to find this information,
				// but the submissionId is part of the URL of this page.
				$submissionId =  intval($_GET['submissionId']);
				$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');
				if ($fidusId != false) {
					// This submission is linked to a Fidus Writer instance, so present
					// link rather the file overview.
					// If the submission file section is requested, we override the
					// entire grid with a link to the file in Fidus Writer. This way
					// there are no surprises of users accidentally trying to add
					// more files or similar.
                    $stageId =  intval($_GET['stageId']);
                    $reviewRound = 0;
                    $reviewRoundId = $_GET['reviewRoundId'];
                    if (isset($reviewRoundId)) {
                        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
                        $reviewRoundObj = $reviewRoundDao->getById($reviewRoundId);
                        $reviewRound = $reviewRoundObj->getRound();
                        $status = $reviewRoundObj->getStatus();
                        if(
                            $status != REVIEW_ROUND_STATUS_REVISIONS_REQUESTED &&
                            $status != REVIEW_ROUND_STATUS_RESUBMITTED &&
                            $title === 'editor.submission.revisions'
                        ) {
                            // The review round has not reached a status where there
                            // would be any author to show in terms of upload files.
                            // So we show an empty author field.
                            $result =& $args[4];
        					$result = '
                            <div class="pkp_controllers_grid">
        						<div class="header">
        						<h4>' . __($title) . '</h4>
        						</div>
                                <div style="text-align: center;">
        							' . __('grid.noItems') . '
                                </div>
        					</div>';
                            return true;
                        }
                    }
                    $revisionType = ($title==='editor.submission.revisions' ? 'Author' : 'Reviewer');
                    $versionString = $this->stageToVersion($stageId, $reviewRound, $revisionType);

					$result =& $args[4];
					$result = '
					<div class="pkp_controllers_grid">
						<div class="header">
						<h4>' . __($title) . '</h4>
						</div>
                        <div style="text-align: center;">
                            <a
                                href="' . $this->getGatewayPluginUrl() . '/documentReview?submissionId=' . $submissionId . '&stageId=' . $stageId . '&version=' . $versionString . '"
                                target="FidusWriter"
                            >
							    ' . __('plugins.generic.fidusWriter.linkText') . '
						    </a>
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
        $reviewRound = $row[4];
        $stageId = $row[2];
        $versionString = $this->stageToVersion($stageId, $reviewRound, 'Reviewer');

		$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');
		if ($fidusId === false) {
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
        $url = $fidusUrl . '/ojs/add_reviewer/' . $fidusId . '/' . $versionString . '/';

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
		$reviewAssignment = $this->getReviewAssignmentByReviewId($reviewId);
        $submissionId = $reviewAssignment->getSubmissionId();
        $reviewRound = $reviewAssignment->getReviewRoundId();
        $stageId = $reviewAssignment->getStageId();
        $versionString = $this->stageToVersion($stageId, $reviewRound, 'Reviewer');

		$fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');

		if ($fidusId === false) {
			// The article was not connected with Fidus Writer, so we send no
			// notification.
			return false;
		}

        $dataArray = [
			'user_id' => $reviewAssignment->getReviewerId(),
			'key' => $this->getApiKey()
		];
        // Then send the email address of reviewer to Fidus Writer.
		$fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');
		$url = $fidusUrl. '/ojs/remove_reviewer/' . $fidusId . '/' . $versionString . '/';
        $this->sendPostRequest($url, $dataArray);
        return false;
    }


    /**
     * Creates new SubmissionRevision in Fidus Writer
     * @param $hookname
     * @param $args
     */
    function callbackNewReviewRound($hookname, $args) {

        $row =& $args[1];
        $submissionId = intval($row[0]);
        $stageId = intval($row[1]);
        $round = intval($row[2]);

        $fidusId = $this->getSubmissionSetting($submissionId, 'fidusId');
		if ($fidusId == false) {
			// Not connected to Fidus Writer
			return false;
		}

        if($round == 1) {
            $oldVersionString = "1.0.0";
            // TODO: What happens if there is a stage 2?
        } else {
            $oldRound = $round - 1;
            $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
            $oldReviewRound = $reviewRoundDao->getReviewRound($submissionId, $stageId, $round);
            // We need to copy a file from the previous revision round. If the author has
            // submitted something for the round, we use that version.
            // Otherwise, we use the Reviewer's version.
            if($oldReviewRound->getStatus()===REVIEW_ROUND_STATUS_RESUBMITTED) {
                $oldRevisionType = 'Author';
            } else {
                $oldRevisionType = 'Reviewer';
            }
            $oldVersionString = $this->stageToVersion($stageId, $oldRound, $oldRevisionType);
        }

        $newVersionString = $this->stageToVersion($stageId, $round, 'Reviewer');


        $dataArray = [
            'old_version' => $oldVersionString,
            'new_version' => $newVersionString,
            'key' => $this->getApiKey(), //shared key between OJS and Editor software
        ];

        $fidusUrl = $this->getSubmissionSetting($submissionId, 'fidusUrl');
        $url = $fidusUrl . '/ojs/create_copy/' . $fidusId . '/';
        $this->sendPostRequest($url, $dataArray);

        return false;
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
     * @return $reviewAssignment
     */
    function getReviewAssignmentByReviewId($reviewId) {
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
        return $reviewAssignment;
    }


    /**
     * This function converts from the kind of versioning information of a document
     * as it's stored to the versioning information as it's stored on the FW side.
     * The main difference is this:
     * On OJS, a stageId is used to determine whether the document is in
     * submission (1), internal review (2), external review (3), copyediting (4),
     * production (5) stage.
     * Within the review stage, one has to know the round.
     * Each round allows for the upload of files, first of the reviewer, then of
     * the author. So we choose to match two version numbers as used in FW to each
     * round, the first one using $revisionType 'Reviewer' (reviewer), the second 'Author'
     * (author).
     * In FW, we have a version string, similar to a software version number with
     * three parts divided by dots, such as: 1.0.0 or 3.1.5 . These numbers are:
     * - The first number represents the stage ID, so it is 1-5.
     * - The second number represents the round if there is one. Otherwise it is 0.
     * - The third number is 0 for the 'Reviewer' version within a round, and 5 for the
     * 'Author' version.
     * @param $stageId
     * @param $reviewRound
     * @param $revisionType
     * @return int
     */
    function stageToVersion($stageId, $reviewRound = 0, $revisionType = 'Reviewer') {
        switch ($stageId) {
            case 1:
                // submission
                return '1.0.0';
                break;
            case 2:
                // internal review
                // TODO: does this also operate with review rounds? Couldn't
                // find it how to do internal reviews.
                if ($revisionType=='Reviewer') {
                    return '2.' . $reviewRound . '.0';
                } else {
                    return '2.' . $reviewRound . '.5';
                }
                break;
            case 3:
                if ($revisionType=='Reviewer') {
                    return '3.' . $reviewRound . '.0';
                } else {
                    return '3.' . $reviewRound . '.5';
                }
                break;
            case 4:
                return '4.0.0';
                break;
            case 5:
                return '5.0.0';
                break;
        }
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

}
