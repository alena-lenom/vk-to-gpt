<?php
declare(strict_types=1);

namespace VkToGpt;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Session\FileSessionStore;

require_once __DIR__.'/ReadOnly.php';
require_once __DIR__.'/VkClient.php';

function buildServer(VkClient $client, string $sessionDirectory): Server
{
    $builder = Server::builder()->setServerInfo('vk-to-gpt', '0.1.0')
        ->setSession(new FileSessionStore($sessionDirectory, 600));
    foreach (ReadOnlyTools::definitions() as $definition) {
        $definition['annotations'] = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => true];
        $builder->add(Tool::fromArray($definition), new class($client, $definition['name']) implements ToolHandlerInterface {
            public function __construct(private VkClient $client, private string $name) {}

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                try {
                    [$method, $params] = ReadOnlyTools::resolve($this->name, $arguments);
                    $result = $this->client->call($method, $params);
                    return new CallToolResult([new TextContent(json_encode($result, JSON_THROW_ON_ERROR))]);
                } catch (\InvalidArgumentException $error) {
                    return CallToolResult::error([new TextContent($error->getMessage())]);
                } catch (\Throwable) {
                    // SDK logs and client responses must never receive raw transport exceptions.
                    return CallToolResult::error([new TextContent('VK read failed (unconfigured, unavailable, denied, or failed)')]);
                }
            }
        });
    }
    return $builder->build();
}
