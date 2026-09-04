<?php

declare(strict_types=1);

/**
 * AbstractPipelineHandler.php
 *
 * PHP Version 8.4
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */

namespace Blackcube\Handler;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\DataResponse\ResponseFactory\JsonResponseFactory;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Pipeline handler: setup → setupMethod → process → prepareOutputData → output.
 *
 * Concrete subclasses must implement process(); the other steps have no-op defaults.
 *
 * - setup()             : handler init (load datas, checks). Return Output to short-circuit.
 * - setupMethod()       : verb-dependent loading (form load on POST, etc.). Return Output to short-circuit.
 * - process()           : business logic. Returns the main Output.
 * - prepareOutputData() : hook to complete/modify the Output before rendering.
 * - download()          : binary content for OutputType::Download.
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */
abstract class AbstractPipelineHandler extends AbstractHandler
{
    public function __construct(
        LoggerInterface $logger,
        HandlerConfig $config,
        WebViewRenderer $viewRenderer,
        ResponseFactoryInterface $responseFactory,
        JsonResponseFactory $jsonResponseFactory,
        UrlGeneratorInterface $urlGenerator,
        Aliases $aliases,
        protected CurrentRoute $currentRoute,
    ) {
        parent::__construct(
            logger: $logger,
            config: $config,
            viewRenderer: $viewRenderer,
            responseFactory: $responseFactory,
            jsonResponseFactory: $jsonResponseFactory,
            urlGenerator: $urlGenerator,
            aliases: $aliases,
        );
    }

    protected function setup(): ?Output
    {
        return null;
    }

    protected function setupMethod(): ?Output
    {
        return null;
    }

    abstract protected function process(): Output;

    protected function prepareOutputData(Output $output): Output
    {
        return $output;
    }

    protected function download(): ?string
    {
        return null;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        $output = $this->setup();
        if ($output !== null) {
            return $this->output($output);
        }

        $output = $this->setupMethod();
        if ($output !== null) {
            return $this->output($output);
        }

        $output = $this->process();
        $output = $this->prepareOutputData($output);

        return $this->output($output);
    }

    protected function output(Output $output): ResponseInterface
    {
        return match ($output->getType()) {
            OutputType::Render => $this->render($output->getView() ?? '', $output->getParams()),
            OutputType::Partial => $this->renderPartial($output->getView() ?? '', $output->getParams()),
            OutputType::Json => $this->renderJson($output->getParams()),
            OutputType::Redirect => $this->redirect(
                $output->getParams()['route'] ?? '',
                $output->getParams()['params'] ?? [],
            ),
            OutputType::Download => $this->downloadContent(
                $this->download() ?? '',
                $output->getParams()['filename'] ?? 'download',
                ['mimeType' => $output->getParams()['mimeType'] ?? 'application/octet-stream'],
            ),
        };
    }
}
