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
        $this->enqueueDelayed();
        $this->enqueueUnacknowledged();

        return ($requireAck)
            ? $this->consumeWithAck($quantity)
            : $this->consumeWithoutAck($quantity);
    }

    /**
     * Flushes all messages in a given queue, including messages in related support queues.
     */
    public function flushQueue()
    {
        $delayQueue      = $this->getDelayedQueue($this->queue);
        $ackQueue        = $this->getAckQueue($this->queue);
        $ackStorageQueue = $this->getAckStorageQueue($this->queue);

        $this->client->del([$this->queue, $delayQueue, $ackQueue, $ackStorageQueue]);
    }

    /**
     * Acknowledges the given list of messages.
     *
     * @param RedisEnvelope[] $messageList List of messages to acknowledge.
     *
     * @return void
     */
    public function acknowledge(array $messageList)
    {
        $ackQueue             = $this->getAckQueue($this->queue);
        $ackStorageQueue      = $this->getAckStorageQueue($this->queue);
        $encodedMessageIdList = array_map(
            function (RedisEnvelope $message) {
                return $message->getId();
            },
            $messageList
        );

        $script = <<<'LUA'
local ack_queue, ack_storage_queue = KEYS[1], KEYS[2]

-- Removes all acknowledged messages from the ack and ack_storage structures.
redis.call('zrem', ack_queue, unpack(ARGV, 1, #ARGV))
redis.call('hdel', ack_storage_queue, unpack(ARGV, 1, #ARGV))

return true
LUA;

        call_user_func_array(
            [$this->client, 'eval'],
            array_merge([$script, 2, $ackQueue, $ackStorageQueue], $encodedMessageIdList)
        );
    }

    /**
     * Retrieves the delay queue name for a given queue.
     *
     * @param string $queue Original queue name.
     *
     * @return string
     */
    protected function getDelayedQueue($queue)
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
    protected function getAckQueue($queue)
    {
        return $queue . ':ack';
    }

    /**
     * Retrieves the acknowledgement storage queue name for a given queue.
     *
     * @param string $queue Original queue name.
     *
     * @return string
     */
    protected function getAckStorageQueue($queue)
    {
        return $queue . ':ack:storage';
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
local message_list = redis.call('lrange', queue, 0, quantity - 1)
redis.call('ltrim', queue, quantity, -1)

return message_list
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
local queue, quantity, ack_queue, ack_storage_queue, ack_expiration = KEYS[1], KEYS[2], KEYS[3], KEYS[4], KEYS[5]

-- Retrieves the required number of messages from the queue.
local message_list = redis.call('lrange', queue, 0, quantity - 1)
redis.call('ltrim', queue, quantity, -1)

if(next(message_list) ~= nil) then

    -- Build both the mapped set and hash pairs.
    local scored_pair_list, mapped_pair_list = {}, {}
    for _, message in next, message_list, nil do
        local decoded_message = cjson.decode(message)
        table.insert(scored_pair_list, ack_expiration)
        table.insert(scored_pair_list, decoded_message['id'])
        table.insert(mapped_pair_list, decoded_message['id'])
        table.insert(mapped_pair_list, message)
    end
    
    -- Add the messages to both the ordered set and hash.
    redis.call('zadd', ack_queue, unpack(scored_pair_list, 1, #scored_pair_list))
    redis.call('hmset', ack_storage_queue, unpack(mapped_pair_list, 1, #mapped_pair_list))
end

return message_list
LUA;

        /** @noinspection PhpMethodParametersCountMismatchInspection */
        $messages = array_map(
            '\Prototype\Redis\RedisEnvelope::jsonDeserialize',
            $this->client->eval(
                $script,
                5,
                $this->queue,
                $quantity,
                $this->getAckQueue($this->queue),
                $this->getAckStorageQueue($this->queue),
                $this->getAckExpirationTime()
            )
        );

        return $messages;
    }

    /**
     * Moves delayed messages to a new queue.
     */
    protected function enqueueDelayed()
    {
        $script = <<<'LUA'
local delayed_queue, queue, current_time = KEYS[1], KEYS[2], KEYS[3]

-- Retrieve delayed messages.
local message_list = redis.call('zrangebyscore', delayed_queue, '-inf', current_time)

-- Push any delayed messages into the queue (100 at a time).
if(next(message_list) ~= nil) then
    redis.call('zremrangebyrank', delayed_queue, 0, #message_list - 1)
    for i = 1, #message_list, 100 do
        redis.call('rpush', queue, unpack(message_list, i, math.min(i+99, #message_list)))
    end
end

return true
LUA;

        /** @noinspection PhpMethodParametersCountMismatchInspection */
        $this->client->eval($script, 3, $this->getDelayedQueue($this->queue), $this->queue, $this->getCurrentTime());
    }

    /**
     * Moves unacknowledged messages to a new queue.
     */
    protected function enqueueUnacknowledged()
    {
        $script = <<<'LUA'
local ack_queue, ack_storage_queue, queue, current_time = KEYS[1], KEYS[2], KEYS[3], KEYS[4]

-- Retrieve unacknowledged messages.
local message_id_list = redis.call('zrangebyscore', ack_queue, '-inf', current_time)

-- Push any unacknowledged messages into the queue (100 at a time).
if(next(message_id_list) ~= nil) then
    local message_list = redis.call('hmget', ack_storage_queue, unpack(message_id_list, 1, #message_id_list))
    redis.call('zremrangebyrank', ack_queue, 0, #message_id_list - 1)
    redis.call('hdel', ack_storage_queue, unpack(message_id_list, 1, #message_id_list))
    for i = 1, #message_list, 100 do
        redis.call('rpush', queue, unpack(message_list, i, math.min(i+99, #message_list)))
    end
end

return true
LUA;

        /** @noinspection PhpMethodParametersCountMismatchInspection */
        $this->client->eval(
            $script,
            4,
            $this->getAckQueue($this->queue),
            $this->getAckStorageQueue($this->queue),
            $this->queue,
            $this->getCurrentTime()
        );
    }
}
