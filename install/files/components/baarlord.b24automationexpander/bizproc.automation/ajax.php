<?php
/**
 * @author Leonid Eremin <leosard@yandex.ru>
 */


use baarlord\b24automationexpander\tasks\AutomationService;
use Bitrix\Bizproc\Automation\Engine\Template;
use Bitrix\Bizproc\Automation\Target\BaseTarget;
use Bitrix\Bizproc\Automation\Tracker;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;


define("NOT_CHECK_PERMISSIONS", true);
define("STOP_STATISTICS", true);
define("NO_KEEP_STATISTIC", "Y");
define("NO_AGENT_STATISTIC", "Y");
define("DisableEventsCheck", true);

$siteId = '';
if (isset($_REQUEST['site_id']) && is_string($_REQUEST['site_id'])) {
    $siteId = mb_substr(preg_replace('/[^a-z0-9_]/i', '', $_REQUEST['site_id']), 0, 2);
}
if (!$siteId) {
    define('SITE_ID', $siteId);
}
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
/**
 * @global CUser $USER
 */
if (
        !Loader::includeModule('bizproc') ||
        !Loader::includeModule('baarlord.b24automationexpander')
) {
    die();
}
global $USER, $DB, $APPLICATION;
$curUser = isset($USER) && is_object($USER) ? $USER : null;
if (!$curUser || !$curUser->IsAuthorized() || !check_bitrix_sessid() || $_SERVER['REQUEST_METHOD'] != 'POST') {
    die();
}

CUtil::JSPostUnescape();
$action = !empty($_REQUEST['ajax_action']) ? $_REQUEST['ajax_action'] : null;
if (empty($action)) {
    die('Unknown action!');
}
$APPLICATION->ShowAjaxHead();
$action = mb_strtoupper($action);
$sendResponse = function ($data, array $errors = array(), $plain = false) {
    if ($data instanceof Result) {
        $errors = $data->getErrorMessages();
        $data = $data->getData();
    }

    $result = array('DATA' => $data, 'ERRORS' => $errors);
    $result['SUCCESS'] = count($errors) === 0;
    if (!defined('PUBLIC_AJAX_MODE')) {
        define('PUBLIC_AJAX_MODE', true);
    }
    $GLOBALS['APPLICATION']->RestartBuffer();
    header('Content-Type: application/x-javascript; charset=' . LANG_CHARSET);

    if ($plain) {
        $result = $result['DATA'];
    }

    echo Json::encode($result);
    Application::getInstance()->end();
};
$sendError = function ($error) use ($sendResponse) {
    $sendResponse(array(), array($error));
};

$sendHtmlResponse = function ($html) {
    if (!defined('PUBLIC_AJAX_MODE')) {
        define('PUBLIC_AJAX_MODE', true);
    }
    header('Content-Type: text/html; charset=' . LANG_CHARSET);
    echo $html;
    CMain::FinalActions();
};

CBitrixComponent::includeComponentClass('bitrix:bizproc.automation');

$documentInformation = \BizprocAutomationComponent::unSignDocument($_POST['document_signed']);

if (!$documentInformation) {
    $sendError('Invalid request [document_signed]');
}
list($documentType, $documentCategoryId, $documentId) = $documentInformation;

try {
    $documentType = CBPHelper::ParseDocumentId($documentType);
} catch (CBPArgumentNullException $e) {
    $sendError('Invalid request [document_type]');
}

$runtime = CBPRuntime::GetRuntime();
$runtime->StartRuntime();

$documentService = $runtime->GetService('DocumentService');

$target = $documentService->createAutomationTarget($documentType);

if (!$target || !$target->isAvailable()) {
    $sendError('Automation target is not supported for this document');
}

$checkConfigWritePerms = function () use ($documentType, $documentCategoryId, $curUser, $sendError) {
    if ($curUser->IsAdmin()) {
        return true;
    }
    $projectId = (int)mb_substr($documentType[2], mb_strlen('TASK_PROJECT_'));
    $automationService = new AutomationService();
    if ($automationService->isUserIsModerator($projectId, $curUser->GetID())) {
        return true;
    }
    $canWrite = CBPDocument::CanUserOperateDocumentType(
            CBPCanUserOperateOperation::CreateAutomation,
            $curUser->getId(),
            $documentType,
            ['DocumentCategoryId' => $documentCategoryId]
    );
    if (!$canWrite) {
        $sendError('Access denied!');
    }
};

$checkReadPerms = function ($documentId) use ($documentType, $curUser, $sendError) {
    $tplUser = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
    if ($tplUser->isAdmin()) {
        return true;
    }

    $documentId = [$documentType[0], $documentType[1], $documentId];
    $canRead = CBPDocument::CanUserOperateDocument(
            CBPCanUserOperateOperation::ViewWorkflow,
            $curUser->getId(),
            $documentId
    );
    if (!$canRead) {
        $sendError('Access denied!');
    }
};

switch ($action) {
    case 'GET_ROBOT_DIALOG':
        //Check permissions.
        $checkConfigWritePerms();

        $robotData = isset($_REQUEST['robot']) && is_array($_REQUEST['robot']) ? $_REQUEST['robot'] : null;
        if (!$robotData) {
            $sendError('Empty robot data.');
        }

        $context = isset($_REQUEST['context']) && is_array($_REQUEST['context']) ? $_REQUEST['context'] : null;

        ob_start();
        $APPLICATION->includeComponent(
                'baarlord.b24automationexpander:bizproc.automation',
                '',
                array(
                        'ACTION' => 'ROBOT_SETTINGS',
                        'DOCUMENT_TYPE' => $documentType,
                        'DOCUMENT_CATEGORY_ID' => $documentCategoryId,
                        'ROBOT_DATA' => $robotData,
                        'REQUEST' => $_REQUEST,
                        'CONTEXT' => $context,
                )
        );
        $dialog = ob_get_clean();

        $sendHtmlResponse($dialog);
        break;

    case 'SAVE_ROBOT_SETTINGS':
        //Check permissions.
        $checkConfigWritePerms();

        $robotData = isset($_REQUEST['robot']) && is_array($_REQUEST['robot']) ? $_REQUEST['robot'] : null;
        if (!$robotData) {
            $sendError('Empty robot data.');
        }

        $requestData = isset($_POST['form_data']) && is_array($_POST['form_data']) ? $_POST['form_data'] : array();

        $template = new Template($documentType);
        $saveResult = $template->saveRobotSettings($robotData, $requestData);

        if ($saveResult->isSuccess()) {
            $data = $saveResult->getData();
            CBitrixComponent::includeComponentClass('bitrix:bizproc.automation');
            $data['robot']['viewData'] = \BizprocAutomationComponent::getRobotViewData($data['robot'], $documentType);

            $sendResponse(array('robot' => $data['robot']));
        } else {
            $sendError($saveResult->getErrorMessages());
        }
        break;

    case 'SAVE_AUTOMATION':

        //Check permissions.
        $checkConfigWritePerms();

        //save Templates and Robots
        $templates = isset($_REQUEST['templates']) && is_array($_REQUEST['templates']) ? $_REQUEST['templates'] : [];
        $errors = array();

        //save Triggers
        $updatedTriggers = [];
        $triggers = isset($_REQUEST['triggers']) && is_array($_REQUEST['triggers']) ? $_REQUEST['triggers'] : [];

        $target->prepareTriggersToSave($triggers);
        $updatedTriggers = $target->setTriggers($triggers);

        $updatedTemplates = array();
        foreach ($templates as $templateData) {
            $template = null;
            if ($templateData['ID']) {
                $tpl = WorkflowTemplateTable::getById(
                        $templateData['ID']
                )->fetchObject();
                if ($tpl) {
                    $template = Template::createByTpl($tpl);
                }
            }

            if (!$template) {
                $template = new Template(
                        $documentType,
                        $templateData['DOCUMENT_STATUS']
                );
            }

            if (empty($templateData['IS_EXTERNAL_MODIFIED'])) {
                $robots = isset($templateData['ROBOTS']) && is_array(
                        $templateData['ROBOTS']
                ) ? $templateData['ROBOTS'] : array();

                $result = $template->save($robots, $curUser->GetID());
                if ($result->isSuccess()) {
                    $updatedTemplates[] = BizprocAutomationComponent::getTemplateViewData(
                            $template->toArray(),
                            $documentType
                    );
                } else {
                    $errors = array_merge($errors, $result->getErrorMessages());
                }
            } else {
                $updatedTemplates[] = $template->toArray();
            }
        }

        $target->prepareTriggersToShow($updatedTriggers);
        $sendResponse(array('templates' => $updatedTemplates, 'triggers' => $updatedTriggers), $errors);

        break;

    case 'GET_LOG':
        //Check permissions.
        if (empty($documentId)) {
            $sendError('Wrong document id.');
        }

        $checkReadPerms($documentId);

        /** @var BaseTarget $target */
        $target = $documentService->createAutomationTarget($documentType);

        if (!$target) {
            $sendError('Wrong document type.');
        }

        $target->setDocumentId($documentId);
        $statusList = $target->getDocumentStatusList($documentCategoryId);
        $tracker = new Tracker($target);

        $sendResponse(array('LOG' => $tracker->getLog(array_keys($statusList))));
        break;

    case 'GET_AVAILABLE_TRIGGERS':
        //Check permissions.
        $checkConfigWritePerms();

        /** @var BaseTarget $target */
        $target = $documentService->createAutomationTarget($documentType);

        if (!$target) {
            $sendError('Wrong document type.');
        }

        $sendResponse($target->getAvailableTriggers());
        break;

    default:
        $sendError('Unknown action!');
        break;
}