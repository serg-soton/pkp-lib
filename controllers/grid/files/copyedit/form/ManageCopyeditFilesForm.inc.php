<?php

/**
 * @file controllers/grid/files/copyedit/form/ManageCopyeditFilesForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ManageCopyeditFilesForm
 * @ingroup controllers_grid_files_copyedit
 *
 * @brief Form to add files to the copyedited files grid
 */

use PKP\submissionFile\SubmissionFile;

import('lib.pkp.controllers.grid.files.form.ManageSubmissionFilesForm');

class ManageCopyeditFilesForm extends ManageSubmissionFilesForm
{
    /**
     * Constructor.
     *
     * @param $submissionId int Submission ID.
     */
    public function __construct($submissionId)
    {
        parent::__construct($submissionId, 'controllers/grid/files/copyedit/manageCopyeditFiles.tpl');
    }

    /**
     * Save selection of copyedited files
     *
     * @param $stageSubmissionFiles array List of submission files in this stage.
     * @param $fileStage int SubmissionFile::SUBMISSION_FILE_...
     */
    public function execute($stageSubmissionFiles, $fileStage = null)
    {
        parent::execute($stageSubmissionFiles, SubmissionFile::SUBMISSION_FILE_COPYEDIT);
    }
}
