<?php

/**
 * @file tools/automateCopyeditingTransition.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class automateCopyeditingTransition
 *
 * @ingroup tools
 *
 * @brief CLI tool to automate the transition of submissions from copyediting to a new review round.
 */

require(dirname(__FILE__) . '/bootstrap.php');

class automateCopyeditingTransition extends \PKP\cliTool\CommandLineTool
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
            $submissionCollector->filterByStageIds([WORKFLOW_STAGE_ID_EDITING]);
        }

        $submissions = $submissionCollector->getMany();

        echo "Found " . $submissions->count() . " submissions to process.\n";
        if ($this->dryRun) {
            echo "DRY RUN: No changes will be made.\n";
        }

        foreach ($submissions as $submission) {
            if ($submission->getData('stageId') !== WORKFLOW_STAGE_ID_EDITING) {
                if ($this->submissionId) {
                    echo "Submission " . $submission->getId() . " is not in the copyediting stage (Stage ID: " . $submission->getData('stageId') . "). Skipping.\n";
                }
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

                $this->transition($submission);
            } catch (\Exception $e) {
                echo "Error processing submission " . $submission->getId() . ": " . $e->getMessage() . "\n";
                // Print stack trace for debugging
                echo $e->getTraceAsString() . "\n";
            }
        }

        echo "Done.\n";
    }

    /**
     * Transition a single submission
     */
    protected function transition($submission)
    {
        $contextId = $submission->getData('contextId');
        $editor = $this->user;

        // Step 1: Decision - Back From Copyediting (30)
        // This promotes the submission back to the review stage
        echo "  - Moving back from copyediting...\n";
        if (!$this->dryRun) {
            $this->recordDecision($submission, \PKP\decision\Decision::BACK_FROM_COPYEDITING);
        }

        // Step 2: Decision - New External Round (14)
        // This creates a new review round (Round 2)
        echo "  - Creating new review round...\n";
        if (!$this->dryRun) {
            // Refresh submission to get updated stageId (though we know it should be Stage 3 now)
            $submission = \APP\facades\Repo::submission()->get($submission->getId());
            $this->recordDecision($submission, \PKP\decision\Decision::NEW_EXTERNAL_ROUND);
        }
    }

    /**
     * Record a decision for a submission
     */
    protected function recordDecision($submission, $decisionConst)
    {
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
        ];

        // If it's a review decision, we need the last review round ID
        if ($decisionType->isInReview()) {
            $reviewRoundDao = \PKP\db\DAORegistry::getDAO('ReviewRoundDAO');
            $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId($submission->getId(), $submission->getData('stageId'));
            if ($reviewRound) {
                $params['reviewRoundId'] = $reviewRound->getId();
            }
        }

        $decision = $decisionRepo->newDataObject($params);
        $decisionRepo->add($decision);
    }
}

$tool = new automateCopyeditingTransition($argv ?? []);
$tool->execute();
