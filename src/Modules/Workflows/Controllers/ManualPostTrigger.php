<?php

namespace PublishPress\Future\Modules\Workflows\Controllers;

use PublishPress\Future\Core\HookableInterface;
use PublishPress\Future\Framework\InitializableInterface;
use PublishPress\Future\Core\HooksAbstract as CoreHooksAbstract;
use PublishPress\Future\Modules\Workflows\Models\WorkflowsModel;
use PublishPress\Future\Core\HooksAbstract as FutureCoreHooksAbstract;
use PublishPress\Future\Core\Plugin;
use PublishPress\Future\Modules\Workflows\HooksAbstract;
use PublishPress\Future\Modules\Workflows\Models\PostModel;
use PublishPress\Future\Modules\Workflows\Models\PostTypesModel;
use PublishPress\Future\Modules\Workflows\Module;

class ManualPostTrigger implements InitializableInterface
{
    /**
     * @var HookableInterface
     */
    private $hooks;

    /**
     * @var boolean
     */
    private $isBlockEditor = false;

    public function __construct(HookableInterface $hooks)
    {
        $this->hooks = $hooks;
    }

    public function initialize()
    {
        // Quick Edit
        $this->hooks->addAction(
            FutureCoreHooksAbstract::ACTION_QUICK_EDIT_CUSTOM_BOX,
            [$this, 'registerQuickEditCustomBox'],
            10,
            2
        );

        $this->hooks->addAction(
            FutureCoreHooksAbstract::ACTION_SAVE_POST,
            [$this, 'processQuickEditUpdate']
        );

        $this->hooks->addAction(
            FutureCoreHooksAbstract::ACTION_ADMIN_PRINT_SCRIPTS_EDIT,
            [$this, 'enqueueQuickEditScripts']
        );

        // Block Editor
        $this->hooks->addAction(
            CoreHooksAbstract::ACTION_ENQUEUE_BLOCK_EDITOR_ASSETS,
            [$this, 'enqueueBlockEditorScripts']
        );

        $this->hooks->addAction(
            CoreHooksAbstract::ACTION_REST_API_INIT,
            [$this, 'registerRestField']
        );

        // Classic Editor
        $this->hooks->addAction(
            FutureCoreHooksAbstract::ACTION_ADD_META_BOXES,
            [$this, 'registerClassicEditorMetabox'],
            10,
            2
        );

        $this->hooks->addAction(
            FutureCoreHooksAbstract::ACTION_SAVE_POST,
            [$this, 'processMetaboxUpdate']
        );

        $this->hooks->addAction(
            FutureCoreHooksAbstract::ACTION_ADMIN_ENQUEUE_SCRIPTS,
            [$this, 'enqueueScripts']
        );
    }

    public function registerQuickEditCustomBox($columnName, $postType)
    {
        if ($columnName !== 'expirationdate' || Module::POST_TYPE_WORKFLOW === $postType) {
            return;
        }

        // Check there are workflows with the manual post trigger
        $workflowsModel = new WorkflowsModel();
        $workflows = $workflowsModel->getPublishedWorkflowsWithManualTrigger($postType);

        if (empty($workflows)) {
            return;
        }

        require_once __DIR__ . "/../Views/manual-trigger-quick-edit.html.php";
    }

    public function processQuickEditUpdate($postId)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        // Don't run if this is an auto save
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Don't update data if the function is called for saving revision.
        $postType = get_post_type((int)$postId);
        if ($postType === 'revision') {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $view = $_POST['future_workflow_view'] ?? '';

        if (empty($view) || $view !== 'quick-edit') {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $manuallyEnabledWorkflows = $_POST['future_workflow_manual_trigger'] ?? [];
        $manuallyEnabledWorkflows = array_map('intval', $manuallyEnabledWorkflows);

        $postModel = new PostModel();
        $postModel->load($postId);
        $postModel->setManuallyEnabledWorkflows($manuallyEnabledWorkflows);

        $this->triggerManuallyEnabledWorkflow($postId, $manuallyEnabledWorkflows);
        // phpcs:enable
    }

    private function triggerManuallyEnabledWorkflow($postId, $manuallyEnabledWorkflows)
    {
        // Trigger the action to trigger those workflows
        foreach ($manuallyEnabledWorkflows as $workflowId) {
            $this->hooks->doAction(HooksAbstract::ACTION_MANUALLY_TRIGGERED_WORKFLOW, (int)$postId, (int)$workflowId);
        }
    }

    public function enqueueQuickEditScripts()
    {
        // Only enqueue scripts if we are in the post list table

        if (get_current_screen()->base !== 'edit') {
            return;
        }

        wp_enqueue_style("wp-components");

        wp_enqueue_script("wp-components");
        wp_enqueue_script("wp-plugins");
        wp_enqueue_script("wp-element");
        wp_enqueue_script("wp-data");

        wp_enqueue_script(
            "future_workflow_manual_selection_script_quick_edit",
            Plugin::getScriptUrl('workflowManualSelectionQuickEdit'),
            [
                "wp-plugins",
                "wp-components",
                "wp-element",
                "wp-data",
            ],
            PUBLISHPRESS_FUTURE_VERSION,
            true
        );

        wp_localize_script(
            "future_workflow_manual_selection_script_quick_edit",
            "futureWorkflowManualSelection",
            [
                "nonce" => wp_create_nonce("wp_rest"),
                "apiUrl" => rest_url("publishpress-future/v1"),
            ]
        );
    }

    public function enqueueBlockEditorScripts()
    {
        global $post;

        if (! $post || is_null($post->ID)) {
            error_log('Post is null or ID is not set, cannot enqueue block editor scripts.');
            return;
        }

        $this->isBlockEditor = true;

        $postModel = new PostModel();
        $postModel->load($post->ID);

        $workflowsWithManualTrigger = $postModel->getValidWorkflowsWithManualTrigger($post->ID);

        if (empty($workflowsWithManualTrigger)) {
            return;
        }

        wp_enqueue_style("wp-components");

        wp_enqueue_script("wp-components");
        wp_enqueue_script("wp-plugins");
        wp_enqueue_script("wp-element");
        wp_enqueue_script("wp-data");

        wp_enqueue_script(
            "future_workflow_manual_selection_script_block_editor",
            Plugin::getScriptUrl('workflowManualSelectionBlockEditor'),
            [
                "wp-plugins",
                "wp-components",
                "wp-element",
                "wp-data",
            ],
            PUBLISHPRESS_FUTURE_VERSION,
            true
        );

        wp_localize_script(
            "future_workflow_manual_selection_script_block_editor",
            "futureWorkflowManualSelection",
            [
                "nonce" => wp_create_nonce("wp_rest"),
                "apiUrl" => rest_url("publishpress-future/v1"),
                "postId" => $post->ID,
            ]
        );
    }

    /**
     * Used by the block editor to read and update post attributes.
     *
     * @return void
     */
    public function registerRestField()
    {
        $postTypesModel = new PostTypesModel();
        $postTypes = $postTypesModel->getPostTypes();

        foreach ($postTypes as $postType) {
            register_rest_field(
                $postType->name,
                'publishpress_future_workflow_manual_trigger',
                [
                    'get_callback' => function ($post) {
                        $post = get_post();

                        if (! $post || is_null($post->ID)) {
                            return [
                                'enabledWorkflows' => []
                            ];
                        }

                        $postModel = new PostModel();
                        $postModel->load($post->ID);

                        $enabledWorkflows = $postModel->getManuallyEnabledWorkflows();

                        return [
                            'enabledWorkflows' => $enabledWorkflows,
                        ];
                    },
                    'update_callback' => function ($manualTriggerAttributes, $post) {
                        $postModel = new PostModel();
                        $postModel->load($post->ID);

                        $manuallyEnabledWorkflows = $manualTriggerAttributes['enabledWorkflows'] ?? [];
                        $manuallyEnabledWorkflows = array_map('intval', $manuallyEnabledWorkflows);

                        $postModel->setManuallyEnabledWorkflows($manuallyEnabledWorkflows);

                        $this->triggerManuallyEnabledWorkflow($post->ID, $manuallyEnabledWorkflows);

                        return true;
                    },
                    'schema' => [
                        'description' => 'Workflow Manual Trigger',
                        'type' => 'object',
                    ]
                ]
            );
        }
    }

    public function registerClassicEditorMetabox($postType, $post = null)
    {
        if (!is_object($post) || is_null($post->ID)) {
            error_log('Post is null or ID is not set, cannot load workflows.');
            return;
        }

        if ($this->isBlockEditor) {
            return;
        }

        $postModel = new PostModel();
        $postModel->load($post->ID);

        $workflows = $postModel->getValidWorkflowsWithManualTrigger($post->ID);

        if (empty($workflows)) {
            return;
        }

        add_meta_box(
            'future_workflow_manual_trigger',
            __('Action Workflows', 'post-expirator'),
            [$this, 'renderClassicEditorMetabox'],
            $postType,
            'side',
            'default',
            [$post]
        );
    }

    public function renderClassicEditorMetabox($post)
    {
        require_once __DIR__ . "/../Views/manual-trigger-classic-editor.html.php";
    }

    public function processMetaboxUpdate($postId)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // Don't run if this is an auto save
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Don't update data if the function is called for saving revision.
        $postType = get_post_type((int)$postId);
        if ($postType === 'revision') {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $view = $_POST['future_workflow_view'] ?? '';

        if (empty($view) || $view !== 'classic-editor') {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $manuallyEnabledWorkflows = $_POST['future_workflow_manual_trigger'] ?? [];
        $manuallyEnabledWorkflows = array_map('intval', $manuallyEnabledWorkflows);

        $postModel = new PostModel();
        $postModel->load($postId);
        $postModel->setManuallyEnabledWorkflows($manuallyEnabledWorkflows);

        $this->triggerManuallyEnabledWorkflow($postId, $manuallyEnabledWorkflows);
        // phpcs:enable
    }

    public function enqueueScripts()
    {
        // Only enqueue scripts if we are in the post edit screen
        if (get_current_screen()->id !== 'post') {
            return;
        }

        wp_enqueue_style("wp-components");

        wp_enqueue_script("wp-components");
        wp_enqueue_script("wp-plugins");
        wp_enqueue_script("wp-element");
        wp_enqueue_script("wp-data");

        wp_enqueue_script(
            "future_workflow_manual_selection_script",
            Plugin::getScriptUrl('workflowManualSelectionClassicEditor'),
            [
                "wp-plugins",
                "wp-components",
                "wp-element",
                "wp-data",
            ],
            PUBLISHPRESS_FUTURE_VERSION,
            true
        );

        $post = get_post();

        wp_localize_script(
            "future_workflow_manual_selection_script",
            "futureWorkflowManualSelection",
            [
                "nonce" => wp_create_nonce("wp_rest"),
                "apiUrl" => rest_url("publishpress-future/v1"),
                "postId" => $post->ID,
            ]
        );
    }
}
