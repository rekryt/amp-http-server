<?php

namespace Amp\Http\Server\Driver;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Psr\Log\LoggerInterface as PsrLogger;

interface HttpDriverFactory
{
    /**
     * Selects an HTTP driver based on the given client.
     */
    public function selectDriver(
        Client $client,
        ErrorHandler $errorHandler,
        PsrLogger $logger,
        Options $options
    ): HttpDriver;

    /**
     * @return string[] A list of supported application-layer protocols (ALPNs).
     */
    public function getApplicationLayerProtocols(): array;
}
