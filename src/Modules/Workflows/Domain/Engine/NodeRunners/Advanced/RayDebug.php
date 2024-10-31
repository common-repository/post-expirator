<?php

namespace PublishPress\Future\Modules\Workflows\Domain\Engine\NodeRunners\Advanced;

use Exception;
use PublishPress\Future\Core\HookableInterface;
use PublishPress\Future\Modules\Workflows\Domain\NodeTypes\Advanced\RayDebug as NodeTypeRayDebug;
use PublishPress\Future\Modules\Workflows\HooksAbstract;
use PublishPress\Future\Modules\Workflows\Interfaces\NodeRunnerInterface;
use PublishPress\Future\Modules\Workflows\Interfaces\NodeRunnerProcessorInterface;
use PublishPress\Future\Modules\Workflows\Interfaces\RuntimeVariablesHandlerInterface;
class RayDebug implements NodeRunnerInterface
{
    /**
     * @var HookableInterface
     */
    private $hooks;

    /**
     * @var NodeRunnerProcessorInterface
     */
    private $nodeRunnerProcessor;

    /**
     * @var RuntimeVariablesHandlerInterface
     */
    private $variablesHandler;

    public function __construct(
        HookableInterface $hooks,
        NodeRunnerProcessorInterface $nodeRunnerProcessor,
        RuntimeVariablesHandlerInterface $variablesHandler
    ) {
        $this->hooks = $hooks;
        $this->nodeRunnerProcessor = $nodeRunnerProcessor;
        $this->variablesHandler = $variablesHandler;
    }

    public static function getNodeTypeName(): string
    {
        return NodeTypeRayDebug::getNodeTypeName();
    }

    public function setup(array $step): void
    {
        $this->nodeRunnerProcessor->setup($step, [$this, 'actionCallback']);
    }

    public function actionCallback(array $step)
    {
        $this->hooks->doAction(HooksAbstract::ACTION_WORKFLOW_ENGINE_RUNNING_STEP, $step);

        if (! function_exists('ray')) {
            $workflowId = $this->variablesHandler->getVariable('global.workflow.id');

            $this->nodeRunnerProcessor->logError(
                'Ray is not installed. Please install it from the WordPress plugins directory',
                $workflowId,
                $step
            );
            return;
        }

        $output = null;
        try {
            $node = $this->nodeRunnerProcessor->getNodeFromStep($step);
            $nodeSettings = $this->nodeRunnerProcessor->getNodeSettings($node);

            $dataToOutput = $nodeSettings['data']['dataToOutput'] ?? 'all-input';

            if ($dataToOutput === 'all-input') {
                $onlyInputVariables = $this->variablesHandler->getAllVariables();
                unset($onlyInputVariables['global']);

                $output = $onlyInputVariables;
            } else {
                $output = $this->variablesHandler->getVariable($dataToOutput);
            }
        } catch (\Exception $e) {
            $output = 'Error: ' . $e->getMessage();
        }

        // phpcs:ignore PublishPressStandards.Debug.DisallowDebugFunctions.FoundRayFunction
        $rayMessage = ray($output);

        if (isset($nodeSettings['label'])) {
            $rayMessage->label($nodeSettings['label']);
        }

        if (isset($nodeSettings['color'])) {
            switch ($nodeSettings['color']) {
                case 'red':
                    $rayMessage->red();
                    break;
                case 'green':
                    $rayMessage->green();
                    break;
                case 'blue':
                    $rayMessage->blue();
                    break;
                case 'purple':
                    $rayMessage->purple();
                    break;
                case 'orange':
                    $rayMessage->orange();
                    break;
                case 'gray':
                    $rayMessage->gray();
                    break;
            }
        }
    }
}
