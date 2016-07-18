<?php

/**
 * RedisQueueSimpleBench.php
 *
 * Unauthorized copying or dissemination of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 *
 * @author     Diogo Teixeira <diogo.teixeira@emailbidding.com>
 * @copyright  Copyright (C) Wondeotec SA - All Rights Reserved
 * @license    LICENSE.txt
 */

namespace Prototype\Redis\Benchmark;

use Predis\Client;
use Predis\Pipeline\Pipeline;
use Prototype\Redis\RedisEnvelope;
use Prototype\Redis\RedisQueue;

/**
 *Prototype\Redis\Benchmark\RedisQueueSimpleBench
 *
 * @BeforeMethods({"setup"})
 */
class RedisQueueSimpleBench
{
    const QUEUE_SIZE = 1000;
    const CONSUME_SIZE = 50;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var RedisQueue
     */
    protected $queue;

    /**
     * @var string
     */
    protected $queueName;

    /**
     * Benchmark setup.
     */
    public function setup()
    {
        $this->queueName = 'test.queue';
        $this->client    = new Client('tcp://dev.redis.1:6379');
        $this->queue     = new RedisQueue($this->client, $this->queueName, 1);

        $this->queue->flushQueue();
        $messages = [];
        for ($i = 0; $i < self::QUEUE_SIZE; ++$i) {
            $messages[] = new RedisEnvelope([]);
        }
        $this->queue->publish($messages);
    }

    /**
     * Tests consecutive LPOP instructions.
     */
    public function benchLpopConsecutive()
    {
        $messageList = [];
        for ($i = 0; $i < self::CONSUME_SIZE; $i++) {
            $messageList[] = RedisEnvelope::jsonDeserialize($this->client->lpop($this->queueName));
        }
    }

    /**
     * Tests the internal multiple LPOP mechanism.
     */
    public function benchLpopInternal()
    {
        $messageList = $this->queue->consume(self::CONSUME_SIZE);
    }

    /**
     * Tests multiple LPOP through a pipeline.
     */
    public function benchLpopPipeline()
    {
        $pipeline = new Pipeline($this->client);
        $cmd      = $this->client->getProfile()->createCommand('multi');
        $pipeline->executeCommand($cmd);
        for ($i = 0; $i < self::CONSUME_SIZE; $i++) {
            $cmd = $this->client->getProfile()->createCommand('lpop', [$this->queueName]);
            $pipeline->executeCommand($cmd);
        }
        $cmd = $this->client->getProfile()->createCommand('exec');
        $pipeline->executeCommand($cmd);
        $result      = $pipeline->execute();
        $messageList = array_map(
            '\Prototype\Redis\RedisEnvelope::jsonDeserialize',
            end($result)
        );
    }
}
