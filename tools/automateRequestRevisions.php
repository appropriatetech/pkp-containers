<?php

/**
 * @file tools/automateRequestRevisions.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class automateRequestRevisions
 *
 * @ingroup tools
 *
 * @brief CLI tool to automate requesting revisions for submissions in Review Round 2.
 *        This sets the decision to Request Revisions (no new round) and suppresses notifications.
 */

require(dirname(__FILE__) . '/bootstrap.php');

class automateRequestRevisions extends \PKP\cliTool\CommandLineTool
{
    /** @var bool Whether to run in dry-run mode */
    protected $dryRun = false;

    /** @var int|null Specific submission ID to process */
    protected $submissionId = null;

    /**
     * Constructor
     */
    public function __construct($argv = [])
    {
        parent::__construct($argv);

        if (in_array('--dry-run', $this->argv)) {
            $this->dryRun = true;
        }

        $submissionIdPos = array_search('--submissionId', $this->argv);
        if ($submissionIdPos !== false && isset($this->argv[$submissionIdPos + 1])) {
            $this->submissionId = (int)$this->argv[$submissionIdPos + 1];
        }
    }

    /**
     * Print usage message
     */
    public function usage()
    {
        echo "Usage: php " . $this->scriptName . " [options]\n"
            . "Options:\n"
            . "  --dry-run          Don't make any changes, just show what would be done.\n"
            . "  --submissionId ID  Process only the specified submission.\n";
    }

    /**
     * Execute the tool
     */
    public function execute()
    {
        $submissionCollector = \APP\facades\Repo::submission()->getCollector();
        $submissionCollector->filterByContextIds([\PKP\core\PKPApplication::SITE_CONTEXT_ID_ALL]);

        if ($this->submissionId) {
            $submissionCollector->filterByIds([$this->submissionId]);
        } else {
            // Filter by External Review stage
            $submissionCollector->filterByStageIds([WORKFLOW_STAGE_ID_EXTERNAL_REVIEW]);
        }

        $submissions = $submissionCollector->getMany();

        echo "Found " . $submissions->count() . " submissions to process.\n";
        if ($this->dryRun) {
            echo "DRY RUN: No changes will be made.\n";
        }

        foreach ($submissions as $submission) {
            // Double check stage ID just in case (though collector filtered it)
            if ($submission->getData('stageId') !== WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
                if ($this->submissionId) {
                    echo "Submission " . $submission->getId() . " is not in the External Review stage (Stage ID: " . $submission->getData('stageId') . "). Skipping.\n";
                }
                continue;
            }

            // Check Review Round
            $reviewRoundDao = \PKP\db\DAORegistry::getDAO('ReviewRoundDAO');
            $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), WORKFLOW_STAGE_ID_EXTERNAL_REVIEW);

            if (!$reviewRound) {
                echo "Submission " . $submission->getId() . ": No review round found. Skipping.\n";
                continue;
            }

            if ($reviewRound->getRound() !== 2) {
                if ($this->submissionId) {
                   echo "Submission " . $submission->getId() . ": Current review round is " . $reviewRound->getRound() . " (Expected: 2). Skipping.\n";
                }
                continue;
            }

            // Check if decision is already made (avoid duplicates for same round status)
            // If status is already REVISIONS_REQUESTED, we skip?
            // User requirement: "For each submission in review round 2... request revisions"
            // Usually we only want to do this if they are pending decision.
            // But let's assume we check if they are already in REVISIONS_REQUESTED status.
            if ($reviewRound->getStatus() === \ReviewRound::REVIEW_ROUND_STATUS_REVISIONS_REQUESTED) {
                 echo "Submission " . $submission->getId() . ": Already in Revisions Requested status. Skipping.\n";
                 continue;
            }

            echo "Processing submission " . $submission->getId() . " (" . $submission->getCurrentPublication()->getLocalizedTitle() . ")...\n";

            try {
                // Hack: Inject the context into the request router so that notification managers
                // (like PendingRevisionsNotificationManager) can access it without crashing.
                if (!$this->dryRun) {
                    $context = app()->get('context')->get($submission->getData('contextId'));
                    $request = \APP\core\Application::get()->getRequest();
                    if ($request->getRouter()) {
                        $request->getRouter()->_context = $context;
                    }
                }

                $this->requestRevisions($submission, $reviewRound);
            } catch (\Exception $e) {
                echo "Error processing submission " . $submission->getId() . ": " . $e->getMessage() . "\n";
                // Print stack trace for debugging
                echo $e->getTraceAsString() . "\n";
            }
        }

        echo "Done.\n";
    }

    /**
     * Request revisions for a single submission
     */
    protected function requestRevisions($submission, $reviewRound)
    {
        echo "  - Requesting revisions (Round 2)...\n";

        if ($this->dryRun) {
            return;
        }

        $decisionConst = \PKP\decision\Decision::PENDING_REVISIONS;
        $decisionRepo = \APP\facades\Repo::decision();
        $decisionType = $decisionRepo->getDecisionType($decisionConst);

        if (!$decisionType) {
            throw new \Exception("Unknown decision type: " . $decisionConst);
        }

        $params = [
            'decision' => $decisionConst,
            'submissionId' => $submission->getId(),
            'stageId' => $submission->getData('stageId'),
            'editorId' => $this->user->getId(),
            'reviewRoundId' => $reviewRound->getId(),
            'actions' => [], // Explicitly empty to prevent notifications
        ];

        $decision = $decisionRepo->newDataObject($params);
        $decisionRepo->add($decision);
    }
}

$tool = new automateRequestRevisions($argv ?? []);
$tool->execute();
