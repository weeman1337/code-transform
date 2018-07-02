<?php

namespace Phpactor\CodeTransform\Domain\Refactor;

use Phpactor\CodeTransform\Domain\SourceCode;

interface ExtractExpression
{
    public function __invoke(SourceCode $source, int $offsetStart, int $offsetEnd = null, string $variableName): SourceCode;
}
