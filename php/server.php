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
require_once __DIR__.'/WallCollector.php';
require_once __DIR__.'/SapiEventReader.php';

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
                        [$method, $params] = ReadOnlyTools::resolve(
                            $this->name,
                            $arguments,
                            getenv('VK_PERSONAL_OWNER_ID') ?: null,
                            getenv('VK_COMMUNITY_ID') ?: null,
                        );
                        $result = match ($this->name) {
                            'vk_wall_collect' => WallCollector::collect($this->client, $params),
                            'vk_sapi_wall_replies' => SapiEventReader::read(getenv('VK_SAPI_EVENTS_DIR') ?: null, $params),
                            default => $this->client->call($method, $params),
                        };
                    }
                    return new CallToolResult([new TextContent(json_encode($result, JSON_THROW_ON_ERROR))]);
                } catch (\InvalidArgumentException $error) {
                    return CallToolResult::error([new TextContent($error->getMessage())]);
                } catch (SapiEventSourceException $error) {
                    return CallToolResult::error([new TextContent('SAPI Callback read unavailable (invalid source or provenance)')]);
                } catch (MissingVkCredentialException $error) {
                    // Report the selected credential requirement, never a credential value.
                    return CallToolResult::error([new TextContent($isWrite
                        ? 'VK write failed (unconfigured, unavailable, denied, or failed; check the wall before retrying)'
                        : 'VK read failed: '.$error->getMessage())]);
                } catch (VkApiException $error) {
                    // Writes may already have happened: keep their failure opaque and non-retryable.
                    $actor = in_array($error->credentialType, ['user', 'group', 'service'], true)
                        ? ' using '.$error->credentialType.' credential'
                        : '';
                    return CallToolResult::error([new TextContent($isWrite
                        ? 'VK write failed (unconfigured, unavailable, denied, or failed; check the wall before retrying)'
                        : ($error->vkCode === null
                            ? 'VK read failed (transport or malformed API response)'.$actor
                            : 'VK read failed: '.$method.' returned VK API error '.$error->vkCode.$actor))]);
                } catch (\Throwable $error) {
                    $missingToken = !$isWrite && in_array($error->getMessage(), [
                        'VK_USER_TOKEN is not configured',
                        'VK_SERVICE_KEY or VK_USER_TOKEN is not configured',
                        'VK_SERVICE_KEY, VK_USER_TOKEN, or VK_COMMUNITY_TOKEN is not configured',
                    ], true);
                    return CallToolResult::error([new TextContent($isWrite
                        ? 'VK write failed (unconfigured, unavailable, denied, or failed; check the wall before retrying)'
                        : ($missingToken
                            ? 'VK read failed: required VK token is not configured'
                            : 'VK read failed (transport or unexpected server error)'))]);
                }
            }
        });
    }
    return $builder->build();
}
