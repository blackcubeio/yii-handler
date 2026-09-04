<?php

declare(strict_types=1);

/**
 * Output.php
 *
 * PHP Version 8.4
 *
 * @copyright 2010-2026 Blackcube - Philippe Gaultier
 * @license https://www.blackcube.io/license
 * @link https://www.blackcube.io
 */

namespace Blackcube\Handler;

/**
 * Pipeline output DTO.
 * Carries the response type, parameters and view to render.
 * Immutable: use withers to derive new instances.
 */
class Output
{
    public function __construct(
        private readonly OutputType $type,
        private readonly array $params = [],
        private readonly ?string $view = null,
    ) {
    }

    public function getType(): OutputType
    {
        return $this->type;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getView(): ?string
    {
        return $this->view;
    }

    public function withType(OutputType $type): self
    {
        return new self($type, $this->params, $this->view);
    }

    public function withParams(array $params): self
    {
        return new self($this->type, $params, $this->view);
    }

    public function withMergedParams(array $params): self
    {
        return new self($this->type, [...$this->params, ...$params], $this->view);
    }

    public function withView(?string $view): self
    {
        return new self($this->type, $this->params, $view);
    }
}
