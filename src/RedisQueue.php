<?php

/**
 * Redis queue.
 *
 * This class manages a single Redis based queue.
 *
 * Unauthorized copying or dissemination of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 *
 * @author     Diogo Teixeira <diogo.teixeira@emailbidding.com>
 * @copyright  Copyright (C) Wondeotec SA - All Rights Reserved
 * @license    LICENSE.txt
 */

namespace Prototype\Redis;

use Predis\Client;

/**
 * Prototype\Redis\RedisQueue
 */
class RedisQueue
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $queue;

    /**
     * @var int
     */
    protected $ackTtl;

    /**
     * RedisQueue constructor.
     *
     * @param Client $client Redis client.
     * @param string $queue  Queue name.
     * @param int    $ackTtl Max time to wait for an acknowledgement message.
     */
    public function __construct(Client $client, $queue, $ackTtl)
    {
        $this->client = $client;
        $this->queue  = $queue;
        $this->ackTtl = $ackTtl;
    }

    /**
     * Publishes the given values into a queue.
     *
     * @param RedisEnvelope[] $messageList List of messages to publish.
     * @param int             $delay       Delay with which to publish the messages, 0 for immediate.
     */
    public function publish(array $messageList, $delay = 0)
    {
        $encodedMessages = array_map('json_encode', $messageList);

        /* No delay, push directly to queue. */
        if (! $delay) {
            $this->client->rpush($this->queue, $encodedMessages);

            return;
        }

        /* Push to a delay queue. */
        $delayTimestamp = $this->getDelayExpirationTime($delay);
        $delayQueue     = $this->getDelayedQueue($this->queue);

        $this->client->zadd($delayQueue, array_fill_keys($encodedMessages, $delayTimestamp));
    }

    /**
     * Consume messages from a queue.
     *
     * @param int  $quantity   Number of messages to consume.
     * @param bool $requireAck Flag the indicates whether or not to expect acknowledgement for the retrieved messages.
     *                         Messages that require acknowledgement are automatically enqueued after some time when
     *                         acknowledgement fails.
     *
     * @return RedisEnvelope[]
     */
    public function consume($quantity = 1, $requireAck = false)
    {
        /* Enqueue expired messages. */
        $delayQueue = $this->getDelayedQueue($this->queue);
        $ackQueue   = $this->getAckQueue($this->queue);
        $this->enqueueExpired($delayQueue, $this->queue);
        $this->enqueueExpired($ackQueue, $this->queue);

        return ($requireAck)
            ? $this->consumeWithAck($quantity)
            : $this->consumeWithoutAck($quantity);
    }

    /**
     * Retrieves the delay queue name for a given queue.
     *
     * @param string $queue Original queue name.
     *
     * @return string
     */
    public function getDelayedQueue($queue)
    {
        return $queue . ':delayed';
    }

    /**
     * Retrieves the acknowledgement queue name for a given queue.
     *
     * @param string $queue Original queue name.
     *
     * @return string
     */
    public function getAckQueue($queue)
    {
        return $queue . ':ack';
    }

    /**
     * Flushes all messages in a given queue, including messages in related support queues.
     */
    public function flushQueue()
    {
        $delayQueue = $this->getDelayedQueue($this->queue);
        $ackQueue   = $this->getAckQueue($this->queue);

        $this->client->del([$this->queue, $delayQueue, $ackQueue]);
    }

    /**
     * Acknowledges the given list of messages.
     *
     * @param RedisEnvelope[] $messageList List of messages to acknowledge.
     */
    public function acknowledge(array $messageList)
    {
        $ackQueue        = $this->getAckQueue($this->queue);
        $encodedMessages = array_map('json_encode', $messageList);
        $this->client->zrem($ackQueue, $encodedMessages);
    }

    /**
     * Retrieves the current timestamp.
     *
     * @return int
     */
    protected function getCurrentTime()
    {
        return time();
    }

    /**
     * Retrieves the expiration time of a given delay.
     *
     * @param int $delay
     *
     * @return int
     */
    protected function getDelayExpirationTime($delay)
    {
        return $this->getCurrentTime() + $delay;
    }

    /**
     * Retrieves the expiration time of acknowledgements.
     *
     * @return int
     */
    protected function getAckExpirationTime()
    {
        return $this->getCurrentTime() + $this->ackTtl;
    }

    /**
     * Consumes a given quantity of items, without acknowledgement.
     *
     * @param int $quantity Quantity of messages to consume.
     *
     * @return RedisEnvelope[]
     */
    protected function consumeWithoutAck($quantity)
    {
        $script = <<<'LUA'
local queue, quantity = KEYS[1], KEYS[2]

-- Get a range of keys from the queue and remove them.
local messages = redis.call('lrange', queue, 0, quantity - 1)
redis.call('ltrim', queue, quantity, -1)

return messages
LUA;

        return array_map(
            '\Prototype\Redis\RedisEnvelope::jsonDeserialize',
            $this->client->eval($script, 2, $this->queue, $quantity)
        );
    }

    /**
     * Consumes a given quantity of items, with acknowledgement.
     *
     * @param int $quantity Quantity of messages to consume.
     *
     * @return RedisEnvelope[]
     */
    protected function consumeWithAck($quantity)
    {
        $script = <<<'LUA'
local queue, quantity, ack_queue, ack_expiration = KEYS[1], KEYS[2], KEYS[3], KEYS[4]

-- Retrieves the required number of messages from the queue.
local messages = redis.call('lrange', queue, 0, quantity - 1)
redis.call('ltrim', queue, quantity, -1)

if(next(messages) ~= nil) then

    -- Build pairs using the expiration time as score.
    local scored_pairs = {}
    for _, message in next, messages, nil do
        table.insert(scored_pairs, ack_expiration)
        table.insert(scored_pairs, message)
    end
    
    -- Add the acknowledge messages to an ordered set.
    redis.call('zadd', ack_queue, unpack(scored_pairs, 1, #scored_pairs))
end

return messages
LUA;

        /** @noinspection PhpMethodParametersCountMismatchInspection */
        $messages = array_map(
            '\Prototype\Redis\RedisEnvelope::jsonDeserialize',
            $this->client->eval(
                $script,
                4,
                $this->queue,
                $quantity,
                $this->getAckQueue($this->queue),
                $this->getAckExpirationTime()
            )
        );

        return $messages;
    }

    /**
     * Moves expired messages to a new queue.
     *
     * @param string $originQueue      Name of the queue from which to consume.
     * @param string $destinationQueue Name of the queue in which to publish.
     */
    protected function enqueueExpired($originQueue, $destinationQueue)
    {
        $script = <<<'LUA'
local origin_queue, destination_queue, current_time = KEYS[1], KEYS[2], KEYS[3]

-- Retrieve 'expired' messages.
local messages = redis.call('zrangebyscore', origin_queue, '-inf', current_time)

-- Push any 'expired' messages into the queue (100 at a time).
if(next(messages) ~= nil) then
    redis.call('zremrangebyrank', origin_queue, 0, #messages - 1)
    for i = 1, #messages, 100 do
        redis.call('rpush', destination_queue, unpack(messages, i, math.min(i+99, #messages)))
    end
end

return true
LUA;

        /** @noinspection PhpMethodParametersCountMismatchInspection */
        $this->client->eval($script, 3, $originQueue, $destinationQueue, $this->getCurrentTime());
    }
}
