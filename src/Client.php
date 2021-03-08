<?php

namespace KitHookRabbit;

use KitHook\Adapter;
use KitHook\Builders\MessageBuilder\Builder as MessageBuilder;
use KitHook\Builders\MessageBuilder\ContentBuilder\Builder as ContentBuilder;
use KitHook\Entities\Messages\QueueMessage;
use KitHook\Interfaces\ClientInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Class Client
 * @package KitHookRabbit
 * @method MessageBuilder messageBuilder()
 * @method ContentBuilder contentBuilder()
 * @method void sendHttpGetEmpty(string $uri, ?string $id = null)
 * @method void sendHttpPostEmpty(string $uri, ?string $id = null)
 * @method void sendHttpPutEmpty(string $uri, ?string $id = null)
 * @method void sendHttpDeleteEmpty(string $uri, ?string $id = null)
 * @method void sendHttpGetJson(string $uri, mixed $data, ?string $id = null)
 * @method void sendHttpPostJson(string $uri, mixed $data, ?string $id = null)
 * @method void sendHttpPutJson(string $uri, mixed $data, ?string $id = null)
 * @method void sendHttpDeleteJson(string $uri, mixed $data, ?string $id = null)
 * @method void sendHttpGetForm(string $uri, array $data, ?string $id = null)
 * @method void sendHttpPostForm(string $uri, array $data, ?string $id = null)
 * @method void sendHttpPutForm(string $uri, array $data, ?string $id = null)
 * @method void sendHttpDeleteForm(string $uri, array $data, ?string $id = null)
 */
class Client implements ClientInterface
{
    /** @var array */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var Adapter */
    private $adapter;

    /** @var bool */
    private $isAlreadyInit = false;

    public const CONFIG_RABBIT_ENV = 'environment';

    public const CONFIG_RABBIT_HOST = 'host';
    public const CONFIG_RABBIT_PORT = 'port';
    public const CONFIG_RABBIT_LOGIN = 'login';
    public const CONFIG_RABBIT_PASSWORD = 'password';
    public const CONFIG_RABBIT_QUEUE = 'queue';
    public const CONFIG_RABBIT_VHOST = 'vhost';

    public const ENV_RABBIT_HOST = 'RABBITMQ_HOST';
    public const ENV_RABBIT_PORT = 'RABBITMQ_PORT';
    public const ENV_RABBIT_LOGIN = 'RABBITMQ_LOGIN';
    public const ENV_RABBIT_PASSWORD = 'RABBITMQ_PASSWORD';
    public const ENV_RABBIT_QUEUE = 'RABBITMQ_QUEUE';
    public const ENV_RABBIT_VHOST = 'RABBITMQ_VHOST';

    private $configRabbitHost;
    private $configRabbitPort;
    private $configRabbitLogin;
    private $configRabbitPassword;
    private $configRabbitQueue;
    private $configRabbitVhost;

    /** @var AMQPStreamConnection|null */
    private $connection;
    /** @var AMQPChannel|null */
    private $channel;

    /**
     * Client constructor.
     *
     * @param array $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param QueueMessage $message
     * @throws Throwable
     */
    public function send(QueueMessage $message): void
    {
        $this->initIfNeed();
        $this->connectIfNeed();

        try {
            $queueMessage = new AMQPMessage($message->jsonSerialize());
            $this->channel->basic_publish($queueMessage, '', $this->configRabbitQueue);
        } catch (Throwable $exception) {
            $this->logger->warning($exception->getMessage());
            throw $exception;
        }
    }

    private function initIfNeed(): void
    {
        if ($this->isAlreadyInit) {
            return;
        }

        $environment = $this->config[self::CONFIG_RABBIT_ENV] ?? null;

        if ($environment === null) {
            $environment = $_ENV ?: getenv();
        }

        $this->checkAndFillConfig($environment);

        $this->logger = $this->logger ?? new NullLogger();

        $this->isAlreadyInit = true;
    }

    private function connectIfNeed(): void
    {
        if (!$this->connection instanceof AMQPStreamConnection) {
            $this->connection = new AMQPStreamConnection(
                $this->configRabbitHost,
                $this->configRabbitPort,
                $this->configRabbitLogin,
                $this->configRabbitPassword,
                $this->configRabbitVhost
            );
        }

        if (!$this->connection->isConnected()) {
            $this->connection->reconnect();
        }

        if (!$this->channel instanceof AMQPChannel) {
            $this->channel = $this->connection->channel();
        }
    }

    private function checkAndFillConfig(array $environment): void
    {
        $this->configRabbitHost = $this->config[self::CONFIG_RABBIT_HOST] ?? $environment[self::ENV_RABBIT_HOST] ?? null;
        $this->configRabbitPort = $this->config[self::CONFIG_RABBIT_PORT] ?? $environment[self::ENV_RABBIT_PORT] ?? null;
        $this->configRabbitLogin = $this->config[self::CONFIG_RABBIT_LOGIN] ?? $environment[self::ENV_RABBIT_LOGIN] ?? null;
        $this->configRabbitPassword = $this->config[self::CONFIG_RABBIT_PASSWORD] ?? $environment[self::ENV_RABBIT_PASSWORD] ?? null;
        $this->configRabbitQueue = $this->config[self::CONFIG_RABBIT_QUEUE] ?? $environment[self::ENV_RABBIT_QUEUE] ?? null;
        $this->configRabbitVhost = $this->config[self::CONFIG_RABBIT_VHOST] ?? $environment[self::ENV_RABBIT_VHOST] ?? null;

        if (!$this->configRabbitHost) {
            throw new RuntimeException('Config parameter "host" is required');
        }
        if (!$this->configRabbitPort) {
            throw new RuntimeException('Config parameter "port" is required');
        }
        if (!$this->configRabbitLogin) {
            throw new RuntimeException('Config parameter "login" is required');
        }
        if (!$this->configRabbitPassword) {
            throw new RuntimeException('Config parameter "password" is required');
        }
        if (!$this->configRabbitQueue) {
            throw new RuntimeException('Config parameter "queue" is required');
        }
        if (!$this->configRabbitVhost) {
            throw new RuntimeException('Config parameter "vhost" is required');
        }
    }

    public function __call($name, $arguments)
    {
        if (!$this->adapter instanceof Adapter) {
            $this->adapter = new Adapter($this);
        }

        if (method_exists($this->adapter, $name)) {
            return $this->adapter->$name(...$arguments);
        }

        throw new RuntimeException(sprintf('Unknown method "%s"', $name));
    }

    public function __destruct()
    {
        if ($this->channel instanceof AMQPChannel) {
            $this->channel->close();
        }
        if ($this->connection instanceof AMQPStreamConnection) {
            $this->connection->close();
        }
    }
}
