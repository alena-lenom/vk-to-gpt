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
require_once __DIR__.'/WriteTools.php';
require_once __DIR__.'/VkClient.php';

function buildServer(VkClient $client, string $sessionDirectory): Server
{
    $builder = Server::builder()->setServerInfo('vk-to-gpt', '0.1.0')
        ->setSession(new FileSessionStore($sessionDirectory, 600));
    $definitions = array_merge(ReadOnlyTools::definitions(), WriteTools::definitions());
    foreach ($definitions as $definition) {
        $isWrite = in_array($definition['name'], WriteTools::names(), true);
        $definition['annotations'] = [
            'readOnlyHint' => !$isWrite,
            'destructiveHint' => false,
            'openWorldHint' => true,
            'idempotentHint' => !$isWrite,
        ];
        $name = $definition['name'];
        $builder->add(Tool::fromArray($definition), new class($client, $name) implements ToolHandlerInterface {
            public function __construct(private VkClient $client, private string $name) {}

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                $isWrite = in_array($this->name, WriteTools::names(), true);
                try {
                    if ($isWrite) {
                        [$method, $params, $target] = WriteTools::resolve(
                            $this->name,
                            $arguments,
                            getenv('VK_PERSONAL_OWNER_ID') ?: null,
                            getenv('VK_COMMUNITY_ID') ?: null,
                        );
                        $result = $this->client->call($method, $params, $target);
                    } else {
                        [$method, $params] = ReadOnlyTools::resolve($this->name, $arguments);
                        $result = $this->client->call($method, $params);
                    }
                    return new CallToolResult([new TextContent(json_encode($result, JSON_THROW_ON_ERROR))]);
                } catch (\InvalidArgumentException $error) {
                    return CallToolResult::error([new TextContent($error->getMessage())]);
                } catch (\Throwable) {
                    return CallToolResult::error([new TextContent($isWrite
                        ? 'VK write failed (unconfigured, unavailable, denied, or failed; check the wall before retrying)'
                        : 'VK read failed (unconfigured, unavailable, denied, or failed)')]);
                }
            }
        });
    }
    return $builder->build();
}
