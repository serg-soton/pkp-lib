<?php

/**
 * @file controllers/modals/editorDecision/form/EditorDecisionForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class EditorDecisionForm
 * @ingroup controllers_modals_editorDecision_form
 *
 * @brief Base class for the editor decision forms.
 */

namespace PKP\controllers\modals\editorDecision\form;

use APP\facades\Repo;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use APP\workflow\EditorDecisionActionsManager;

use PKP\db\DAORegistry;
use PKP\form\Form;
use PKP\notification\PKPNotification;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submissionFile\SubmissionFile;

class EditorDecisionForm extends Form
{
    /** @var Submission The submission associated with the editor decision */
    public $_submission;

    /** @var int The stage ID where the decision is being made */
    public $_stageId;

    /** @var ReviewRound Only required when in review stages */
    public $_reviewRound;

    /** @var integer The decision being taken */
    public $_decision;


    /**
     * Constructor.
     *
     * @param $submission Submission
     * @param $stageId int
     * @param $template string The template to display
     * @param $reviewRound ReviewRound
     */
    public function __construct($submission, $decision, $stageId, $template, $reviewRound = null)
    {
        parent::__construct($template);
        $this->_submission = $submission;
        $this->_stageId = $stageId;
        $this->_reviewRound = $reviewRound;
        $this->_decision = $decision;

        // Validation checks for this form
        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }

    //
    // Getters and Setters
    //
    /**
     * Get the decision
     *
     * @return integer
     */
    public function getDecision()
    {
        return $this->_decision;
    }

    /**
     * Get the submission
     *
     * @return Submission
     */
    public function getSubmission()
    {
        return $this->_submission;
    }

    /**
     * Get the stage Id
     *
     * @return int
     */
    public function getStageId()
    {
        return $this->_stageId;
    }

    /**
     * Get the review round object.
     *
     * @return ReviewRound
     */
    public function getReviewRound()
    {
        return $this->_reviewRound;
    }

    //
    // Overridden template methods from Form
    //
    /**
     * @see Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['selectedFiles']);
        parent::initData();
    }


    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $submission = $this->getSubmission();

        $reviewRound = $this->getReviewRound();
        if ($reviewRound instanceof \PKP\submission\reviewRound\ReviewRound) {
            $this->setData('reviewRoundId', $reviewRound->getId());
        }

        $this->setData('stageId', $this->getStageId());

        $templateMgr = TemplateManager::getManager($request);
        $stageDecisions = (new EditorDecisionActionsManager())->getStageDecisions($request->getContext(), $submission, $this->getStageId());
        $templateMgr->assign([
            'decisionData' => $stageDecisions[$this->getDecision()],
            'submissionId' => $submission->getId(),
            'submission' => $submission,
        ]);

        return parent::fetch($request, $template, $display);
    }


    //
    // Private helper methods
    //
    /**
     * Initiate a new review round and add selected files
     * to it. Also saves the new round to the submission.
     *
     * @param $submission Submission
     * @param $stageId integer One of the WORKFLOW_STAGE_ID_* constants.
     * @param $request Request
     * @param $status integer One of the REVIEW_ROUND_STATUS_* constants.
     *
     * @return $newRound integer The round number of the new review round.
     */
    public function _initiateReviewRound($submission, $stageId, $request, $status = null)
    {

        // If we already have review round for this stage,
        // we create a new round after the last one.
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var ReviewRoundDAO $reviewRoundDao */
        $lastReviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), $stageId);
        if ($lastReviewRound) {
            $newRound = $lastReviewRound->getRound() + 1;
        } else {
            // If we don't have any review round, we create the first one.
            $newRound = 1;
        }

        // Create a new review round.
        $reviewRound = $reviewRoundDao->build($submission->getId(), $stageId, $newRound, $status);

        // Check for a notification already in place for the current review round.
        $notificationDao = DAORegistry::getDAO('NotificationDAO'); /** @var NotificationDAO $notificationDao */
        $notificationFactory = $notificationDao->getByAssoc(
            ASSOC_TYPE_REVIEW_ROUND,
            $reviewRound->getId(),
            null,
            PKPNotification::NOTIFICATION_TYPE_REVIEW_ROUND_STATUS,
            $submission->getContextId()
        );

        // Create round status notification if there is no notification already.
        if (!$notificationFactory->next()) {
            $notificationMgr = new NotificationManager();
            $notificationMgr->createNotification(
                $request,
                null,
                PKPNotification::NOTIFICATION_TYPE_REVIEW_ROUND_STATUS,
                $submission->getContextId(),
                ASSOC_TYPE_REVIEW_ROUND,
                $reviewRound->getId(),
                Notification::NOTIFICATION_LEVEL_NORMAL
            );
        }

        // Add the selected files to the new round.
        $fileStage = $stageId == WORKFLOW_STAGE_ID_INTERNAL_REVIEW
            ? SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE
            : SubmissionFile::SUBMISSION_FILE_REVIEW_FILE;

        foreach (['selectedFiles', 'selectedAttachments'] as $userVar) {
            $selectedFiles = $this->getData($userVar);
            if (is_array($selectedFiles)) {
                foreach ($selectedFiles as $fileId) {
                    $oldSubmissionFile = Repo::submissionFile()
                        ->get($fileId);
                    $oldSubmissionFile->setData('fileStage', $fileStage);
                    $oldSubmissionFile->setData('sourceSubmissionFileId', $fileId);
                    $oldSubmissionFile->setData('assocType', null);
                    $oldSubmissionFile->setData('assocId', null);

                    $submissionFileId = Repo::submissionFile()
                        ->add($oldSubmissionFile);

                    Repo::submissionFile()
                        ->dao
                        ->assignRevisionToReviewRound(
                            $submissionFileId,
                            $reviewRound
                        );
                }
            }
        }

        return $newRound;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\controllers\modals\editorDecision\form\EditorDecisionForm', '\EditorDecisionForm');
}
