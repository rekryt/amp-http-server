<?php

namespace Aerys;

use Amp\Reactor;
use League\CLImate\CLImate;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\LoggerAwareInterface as LoggerAware;

class Bootstrapper {

    /**
     * Parse command line args
     *
     * @return \League\CLImate\CLImate
     */
    public static function loadCommandArgs(): CLImate {
        $climate = new CLImate;
        $climate->arguments->add([
            "help" => [
                "prefix"      => "h",
                "longPrefix"  => "help",
                "description" => "Display the help screen",
                "noValue"     => true,
            ],
            "debug" => [
                "prefix"       => "d",
                "longPrefix"   => "user",
                "description"  => "Enable server debug mode",
                "noValue"     => true,
            ],
            "config" => [
                "prefix"      => "c",
                "longPrefix"  => "config",
                "description" => "Specify a custom server config path",
                "noValue"     => true,
            ],
            "workers" => [
                "prefix"      => "w",
                "longPrefix"  => "workers",
                "description" => "Specify the number of worker processes to spawn",
                "castTo"      => "int",
            ],
            "remote" => [
                "prefix"      => "r",
                "longPrefix"  => "remote",
                "description" => "Specify a command port on which to listen",
                "castTo"      => "int",
            ],
        ]);

        $climate->arguments->parse();

        return $climate;
    }

    /**
     * Bootstrap a server from command line options
     *
     * @param \Amp\Reactor $reactor
     * @param array $cliArgs An array of command line arguments
     * @return array
     */
    public static function boot(Reactor $reactor, Logger $logger, array $cliArgs): array {
        $configFile = self::selectConfigFile($cliArgs);
        $forceDebug = $cliArgs["debug"];

        if (include($configFile)) {
            $hosts = Host::getDefinitions() ?: [new Host];
        } else {
            throw new \DomainException(
                "Config file inclusion failure: {$configFile}"
            );
        }

        if (!defined("AERYS_OPTIONS")) {
            $options = [];
        } elseif (is_array(AERYS_OPTIONS)) {
            $options = AERYS_OPTIONS;
        } else {
            throw new \DomainException(
                "Invalid AERYS_OPTIONS constant: array expected, got " . gettype(AERYS_OPTIONS)
            );
        }

        // Override the config file debug setting if indicated on the command line
        if ($forceDebug) {
            $options["debug"] = true;
        }

        $options = self::generateOptionsObjFromArray($options);

        $server = new Server($reactor, $logger, $options->debug);
        $vhostGroup = new VhostGroup;
        foreach ($hosts as $host) {
            $vhost = self::buildVhost($server, $logger, $host);
            $vhostGroup->addHost($vhost);
        }

        $addrCtxMap = [];
        $addresses = $vhostGroup->getBindableAddresses();
        $tlsBindings = $vhostGroup->getTlsBindingsByAddress();
        $backlogSize = $options->socketBacklogSize;
        $shouldReusePort = empty($options->debug);

        foreach ($addresses as $address) {
            $context = stream_context_create(["socket" => [
                "backlog"      => $backlogSize,
                "so_reuseport" => $shouldReusePort,
            ]]);
            if (isset($tlsBindings[$address])) {
                stream_context_set_option($context, ["ssl" => $tlsBindings[$address]]);
            }
            $addrCtxMap[$address] = $context;
        }

        $rfc7230Server = new Rfc7230Server($reactor, $vhostGroup, $options, $logger);
        $server->attach($rfc7230Server);

        return [$server, $options, $addrCtxMap, $rfc7230Server];
    }

    private static function selectConfigFile(array $cliArgs): string {
        if (!empty($cliArgs["config"])) {
            return is_dir($cliArgs["config"])
                ? rtrim($cliArgs["config"], "/") . "/config.php"
                : $cliArgs["config"];
        }
        $paths = [
            __DIR__ . "/../config.php",
            __DIR__ . "/../etc/config.php",
            __DIR__ . "/../bin/config.php",
            "/etc/aerys/config.php",
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \DomainException(
            "No config file found"
        );
    }

    private static function generateOptionsObjFromArray(array $optionsArray): Options {
        try {
            $optionsObj = new Options;
            foreach ($optionsArray as $key => $value) {
                $optionsObj->{$key} = $value;
            }
            return $optionsObj->debug ? $optionsObj : self::generatePublicOptionsStruct($optionsObj);
        } catch (\BaseException $e) {
            throw new \DomainException(
                "Failed assigning options from config file", 0, $e
            );
        }
    }

    private static function generatePublicOptionsStruct(Options $options): Options {
        $code = "return new class extends \Aerys\Options {\n\tuse \Amp\Struct;\n";
        foreach ((new \ReflectionClass($options))->getProperties() as $property) {
            $name = $property->getName();
            $value = $options->{$name};
            $code .= "\tpublic \${$property} = " . var_export($value, true) . ";\n";
        }
        $code .= "};\n";

        return eval($code);
    }

    private static function buildVhost(Server $server, Logger $logger, Host $host) {
        try {
            $hostExport = $host->export();
            $address = $hostExport["address"];
            $port = $hostExport["port"];
            $name = $hostExport["name"];
            $actions = $hostExport["actions"];
            $filters = $hostExport["filters"];
            $application = self::buildApplication($server, $logger, $actions);
            $vhost = new Vhost($name, $address, $port, $application, $filters);
            if ($crypto = $hostExport["crypto"]) {
                $vhost->setCrypto($crypto);
            }

            return $vhost;
        } catch (\BaseException $previousException) {
            throw new \DomainException(
                "Failed building Vhost instance",
                $code = 0,
                $previousException
            );
        }
    }

    private static function buildApplication(Server $server, Logger $logger, array $actions) {
        foreach ($actions as $key => $action) {
            if (!is_callable($action)) {
                throw new \DomainException(
                    "Application action at index {$key} is not callable"
                );
            }
            if ($action instanceof ServerObserver) {
                $server->attach($action);
            } elseif (is_array($action) && is_object($action[0]) && $action[0] instanceof ServerObserver) {
                $server->attach($action[0]);
            }
            if ($action instanceof LoggerAware) {
                $action->setLogger($logger);
            } elseif (is_array($action) && is_object($action[0]) && $action[0] instanceof LoggerAware) {
                $action[0]->setLogger($logger);
            }
        }

        switch (count($actions)) {
            case 0:
                return function(Request $request, Response $response) {
                    $response->end("<html><body><h1>It works!</h1></body></html>");
                };
            case 1:
                return current($actions);
            default:
                return function(Request $request, Response $response) use ($actions): \Generator {
                    foreach ($actions as $action) {
                        $result = ($action)($request, $response);
                        if ($result instanceof \Generator) {
                            yield from $result;
                        }
                        if ($response->state() & Response::STARTED) {
                            return;
                        }
                    }
                };
        }
    }

}
