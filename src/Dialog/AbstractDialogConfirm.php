<?php

declare(strict_types=1);

/**
 * AbstractDialogConfirm.php
 *
 * PHP Version 8.4
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */

namespace Blackcube\Handler\Dialog;

use Blackcube\Bleet\Enums\DialogAction;
use Blackcube\Bleet\Enums\UiColor;
use Blackcube\Bleet\Helper\AureliaCommunication;
use Blackcube\BridgeModel\BridgeFormModel;
use Blackcube\Handler\AbstractPipelineHandler;
use Blackcube\Handler\HandlerConfig;
use Blackcube\Handler\Output;
use Blackcube\Handler\OutputType;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\DataResponse\ResponseFactory\JsonResponseFactory;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * AJAX dialog — confirmation step.
 * Pipeline: reload form from session, revalidate, then confirm.
 *
 * Concrete classes implement:
 * - getSessionPrefix()
 * - createForm()
 * - handleConfirm($form, $sessionData) — populateModel + save, returns Output JSON
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */
abstract class AbstractDialogConfirm extends AbstractPipelineHandler
{
    protected ?BridgeFormModel $form = null;
    protected ?array $sessionData = null;

    public function __construct(
        LoggerInterface $logger,
        HandlerConfig $config,
        WebViewRenderer $viewRenderer,
        ResponseFactoryInterface $responseFactory,
        JsonResponseFactory $jsonResponseFactory,
        UrlGeneratorInterface $urlGenerator,
        Aliases $aliases,
        CurrentRoute $currentRoute,
        protected SessionInterface $session,
    ) {
        parent::__construct(
            logger: $logger,
            config: $config,
            viewRenderer: $viewRenderer,
            responseFactory: $responseFactory,
            jsonResponseFactory: $jsonResponseFactory,
            urlGenerator: $urlGenerator,
            aliases: $aliases,
            currentRoute: $currentRoute,
        );
    }

    abstract protected function getSessionPrefix(): string;

    abstract protected function createForm(): BridgeFormModel;

    abstract protected function handleConfirm(BridgeFormModel $form, array $sessionData): Output;

    protected function setup(): ?Output
    {
        $confirmationId = $this->currentRoute->getArgument('confirmationId');
        $sessionKey = $this->getSessionPrefix().$confirmationId;
        $this->sessionData = $this->session->get($sessionKey);

        if ($this->sessionData === null) {
            return new Output(OutputType::Json, [
                ...AureliaCommunication::dialog(DialogAction::Close),
                ...AureliaCommunication::toast('Error', 'Session expired, please start again.', UiColor::Danger),
            ]);
        }

        $this->session->remove($sessionKey);

        $this->form = $this->createForm();
        $this->form->load($this->sessionData['bodyParams']);

        if ($this->form->validate() === false) {
            return new Output(OutputType::Json, [
                ...AureliaCommunication::dialog(DialogAction::Close),
                ...AureliaCommunication::toast('Error', 'Invalid data.', UiColor::Danger),
            ]);
        }

        return null;
    }

    protected function process(): Output
    {
        return $this->handleConfirm($this->form, $this->sessionData);
    }
}
