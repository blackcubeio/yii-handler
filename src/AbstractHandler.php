<?php

declare(strict_types=1);

/**
 * AbstractHandler.php
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
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\DataResponse\ResponseFactory\JsonResponseFactory;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Base handler shared across all Blackcube applications.
 * Provides view rendering, JSON responses, redirects, downloads, request helpers.
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */
abstract class AbstractHandler implements RequestHandlerInterface
{
    protected bool $debug = false;
    protected ServerRequestInterface $request;

    public function __construct(
        protected LoggerInterface $logger,
        protected HandlerConfig $config,
        protected WebViewRenderer $viewRenderer,
        protected ResponseFactoryInterface $responseFactory,
        protected JsonResponseFactory $jsonResponseFactory,
        protected UrlGeneratorInterface $urlGenerator,
        protected Aliases $aliases,
    ) {
        $this->debug = $this->config->debug;
    }

    protected function getLayout(): string
    {
        return $this->aliases->get($this->config->layoutAlias);
    }

    protected function getViewPath(): string
    {
        return $this->aliases->get($this->config->viewsAlias);
    }

    /**
     * Returns the parsed body parameters.
     * Returns null for GET requests.
     *
     * @return array<string, mixed>|null
     */
    protected function getBodyParams(): ?array
    {
        if ($this->request->getMethod() === Method::GET) {
            return null;
        }
        return $this->request->getParsedBody();
    }

    protected function render(string $view, array $parameters = []): ResponseInterface
    {
        return $this->viewRenderer
            ->withViewPath($this->getViewPath())
            ->withLayout($this->getLayout())
            ->render($this->resolveView($view), $parameters)
            ->withStatus(Status::OK);
    }

    protected function renderPartial(string $view, array $parameters = []): ResponseInterface
    {
        return $this->viewRenderer
            ->withViewPath($this->getViewPath())
            ->withLayout(null)
            ->render($this->resolveView($view), $parameters)
            ->withStatus(Status::OK);
    }

    protected function renderJson(array $data): ResponseInterface
    {
        return $this->jsonResponseFactory->createResponse($data);
    }

    protected function redirect(string $routeName, array $parameters = [], int $status = Status::FOUND): ResponseInterface
    {
        $url = $this->urlGenerator->generate($routeName, $parameters);
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader(Header::LOCATION, $url);
    }

    protected function isAjax(): bool
    {
        return $this->request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    protected function isAjaxify(): bool
    {
        return $this->request->getHeaderLine('X-Requested-For') === 'Ajaxify';
    }

    /**
     * Resolves a view path using the namespace convention.
     *
     * - Path starting with `/` → used as-is (Yii native basePath-relative convention)
     *   ex: `//Commons/_confirm-content` → viewPath + `/Commons/_confirm-content.php`
     * - Simple path `xxx` → prefixed with `//` + parent folder of the handler class
     *   ex: handler `App\Handlers\Admin\Agences\Detail` + `detail` → `//Admin/Agences/detail`
     *   ex: handler `App\Handlers\NotFound` + `not-found` → `//not-found`
     *
     * The handler namespace prefix to strip is configured via HandlerConfig::handlerNamespacePrefix.
     */
    protected function resolveView(string $view): string
    {
        if (str_starts_with($view, '/') === true) {
            return $view;
        }

        $prefix = $this->config->handlerNamespacePrefix;
        $class = static::class;

        if (str_starts_with($class, $prefix) === true) {
            $relative = substr($class, strlen($prefix));
        } else {
            $relative = $class;
        }

        $parts = explode('\\', $relative);
        array_pop($parts);
        $parentDir = implode('/', $parts);

        return $parentDir === '' ? '//'.$view : '//'.$parentDir.'/'.$view;
    }

    /**
     * Returns a response with downloadable content.
     *
     * @param string $content The content to download
     * @param string $filename The filename for the download
     * @param array{mimeType?: string, attachment?: string} $options Download options
     */
    protected function downloadContent(string $content, string $filename, array $options = []): ResponseInterface
    {
        $mimeType = $options['mimeType'] ?? 'application/octet-stream';
        $attachment = $options['attachment'] ?? 'attachment';

        $response = $this->responseFactory->createResponse();
        $response->getBody()->write($content);

        return $response
            ->withHeader(Header::CONTENT_TYPE, $mimeType)
            ->withHeader(Header::CONTENT_DISPOSITION, $attachment.'; filename="'.$filename.'"');
    }

    /**
     * Returns a response with a downloadable file.
     *
     * @param string $filepath The path to the file
     * @param string|null $filename The filename for the download (defaults to basename)
     * @param array{mimeType?: string, attachment?: string} $options Download options
     */
    protected function downloadFile(string $filepath, ?string $filename = null, array $options = []): ResponseInterface
    {
        $filename = $filename ?? basename($filepath);
        $mimeType = $options['mimeType'] ?? mime_content_type($filepath) ?: 'application/octet-stream';
        $options['mimeType'] = $mimeType;
        $content = file_get_contents($filepath);

        return $this->downloadContent($content, $filename, $options);
    }
}
