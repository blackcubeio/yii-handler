<?php

declare(strict_types=1);

/**
 * AbstractDialogInit.php
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
 * AJAX dialog — submit (validate + session) + init (load session + open modal).
 * Pipeline:
 *  - submit mode (no confirmationId): validate form, store in session, return modalUrl JSON
 *  - init mode (confirmationId in route): load from session, render modal content
 *
 * Concrete classes implement:
 * - getSessionPrefix()
 * - createForm()
 * - handleSubmit($form, $bodyParams) — stores in session, returns Output JSON (modalUrl)
 * - handleInit($sessionData, $confirmationId) — renders modal content, returns Output JSON
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */
abstract class AbstractDialogInit extends AbstractPipelineHandler
{
    protected ?BridgeFormModel $form = null;
    protected ?array $sessionData = null;
    protected ?string $confirmationId = null;

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

    abstract protected function handleSubmit(BridgeFormModel $form, ?array $bodyParams): Output;

    abstract protected function handleInit(array $sessionData, string $confirmationId): Output;

    protected function setup(): ?Output
    {
        $this->confirmationId = $this->currentRoute->getArgument('confirmationId');

        if ($this->confirmationId !== null) {
            $sessionKey = $this->getSessionPrefix().$this->confirmationId;
            $this->sessionData = $this->session->get($sessionKey);

            if ($this->sessionData === null) {
                return new Output(OutputType::Json, [
                    ...AureliaCommunication::dialog(DialogAction::Close),
                    ...AureliaCommunication::toast('Error', 'Session expired, please start again.', UiColor::Danger),
                ]);
            }

            return null;
        }

        $this->form = $this->createForm();
        $bodyParams = $this->getBodyParams();
        if ($bodyParams !== null) {
            $this->form->load($bodyParams);
        }

        if ($this->form->validate() === false) {
            return new Output(OutputType::Json, [
                ...AureliaCommunication::toast('Validation error', 'Please check the form fields.', UiColor::Danger),
            ]);
        }

        return null;
    }

    protected function process(): Output
    {
        if ($this->confirmationId !== null) {
            return $this->handleInit($this->sessionData, $this->confirmationId);
        }

        return $this->handleSubmit($this->form, $this->getBodyParams());
    }

    protected function storeInSession(array $sessionPayload): string
    {
        $confirmationId = bin2hex(random_bytes(16));
        $this->session->set($this->getSessionPrefix().$confirmationId, $sessionPayload);
        return $confirmationId;
    }
}
