<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers;

use PhpMyAdmin\Html\MySQLDocumentation;
use PhpMyAdmin\Message;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;

use function __;
use function defined;

abstract class AbstractController
{
    public function __construct(protected ResponseRenderer $response, protected Template $template)
    {
    }

    /** @param array<string, mixed> $templateData */
    protected function render(string $templatePath, array $templateData = []): void
    {
        $this->response->addHTML($this->template->render($templatePath, $templateData));
    }

    /** @param string[] $files */
    protected function addScriptFiles(array $files): void
    {
        $header = $this->response->getHeader();
        $scripts = $header->getScripts();
        $scripts->addFiles($files);
    }

    /** @param array<string, mixed> $params */
    protected function redirect(string $route, array $params = []): void
    {
        if (defined('TESTSUITE')) {
            return;
        }

        $this->response->redirect('./index.php?route=' . $route . Url::getCommonRaw($params, '&'));
    }

    /**
     * Function added to avoid path disclosures.
     * Called by each script that needs parameters, it displays
     * an error message and, by default, stops the execution.
     *
     * @param bool $request Check parameters in request
     * @psalm-param non-empty-list<non-empty-string> $params The names of the parameters needed by the calling script
     */
    protected function checkParameters(array $params, bool $request = false): void
    {
        $foundError = false;
        $errorMessage = '';
        if ($request) {
            $array = $_REQUEST;
        } else {
            $array = $GLOBALS;
        }

        foreach ($params as $param) {
            if (isset($array[$param]) && $array[$param] !== '') {
                continue;
            }

            $errorMessage .=
                __('Missing parameter:') . ' '
                . $param
                . MySQLDocumentation::showDocumentation('faq', 'faqmissingparameters', true)
                . '[br]';
            $foundError = true;
        }

        if (! $foundError) {
            return;
        }

        $this->response->setHttpResponseCode(400);
        $this->response->setRequestStatus(false);
        $this->response->addHTML(Message::error($errorMessage)->getDisplay());

        $this->response->callExit();
    }

    /** @psalm-param int<400,599> $statusCode */
    protected function sendErrorResponse(string $message, int $statusCode = 400): void
    {
        $this->response->setHttpResponseCode($statusCode);
        $this->response->setRequestStatus(false);

        if ($this->response->isAjax()) {
            $this->response->addJSON('isErrorResponse', true);
            $this->response->addJSON('message', $message);

            return;
        }

        $this->response->addHTML(Message::error($message)->getDisplay());
    }
}
